import fs from 'node:fs/promises';
import path from 'node:path';
import { createRequire } from 'node:module';
const sourceRoot = path.resolve(process.argv[2] || path.join(import.meta.dirname, '../..'));
const outputRoot = path.resolve(process.argv[3] || path.join(sourceRoot, 'wordpress-org/assets'));
const require = createRequire(path.join(sourceRoot, 'site/package.json'));
const sharp = require('sharp');
await fs.mkdir(outputRoot, { recursive: true });
const markSource = await fs.readFile(path.join(sourceRoot, 'site/src/components/Mark.astro'), 'utf8');
const markPath = markSource.match(/<path d="([^"]+)"/)[1];
const screenshotPath = path.join(sourceRoot, 'wordpress-org/assets/screenshot-1.jpg');
const screenshot = await fs.readFile(screenshotPath);
const screenshotHref = `data:image/jpeg;base64,${screenshot.toString('base64')}`;
const icon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256" role="img" aria-labelledby="title desc"><title id="title">Bhranti</title><desc id="desc">The existing Bhranti sparkle mark in WordPress blue on charcoal.</desc><rect width="256" height="256" rx="40" fill="#1e1e1e"/><g transform="translate(51 40) scale(11)" fill="#72aee6"><path d="${markPath}"/></g></svg>\n`;
await fs.writeFile(path.join(outputRoot, 'icon.svg'), icon);
for (const size of [128,256]) await sharp(Buffer.from(icon)).resize(size,size).png().toFile(path.join(outputRoot,`icon-${size}x${size}.png`));
const banner = `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1544" height="500" viewBox="0 0 1544 500" role="img" aria-labelledby="title description">
<title id="title">Bhranti — A helping hand for your WordPress.</title>
<desc id="description">Bhranti's existing blue sparkle mark and name on charcoal beside an unchanged screenshot of the WordPress editor with the Bhranti conversation open.</desc>
<rect width="1544" height="500" fill="#1e1e1e"/>
<g transform="translate(64 69) scale(3.6)" fill="#72aee6"><path d="${markPath}"/></g>
<text x="61" y="238" fill="#f0f0f1" font-family="Helvetica, Arial, sans-serif" font-weight="600" font-size="105" letter-spacing="-5">Bhranti<tspan fill="#72aee6">.</tspan></text>
<text x="64" y="310" fill="#f0f0f1" font-family="Helvetica, Arial, sans-serif" font-weight="400" font-size="34" letter-spacing="-.7">A helping hand for</text>
<text x="64" y="358" fill="#b8d6ee" font-family="Georgia, 'Times New Roman', serif" font-style="italic" font-weight="400" font-size="42" letter-spacing="-1.2">your WordPress.</text>
<rect x="636" y="40" width="862" height="420" rx="12" fill="#282828" stroke="#45494d" stroke-width="2"/>
<image x="648" y="52" width="838" height="395.9" xlink:href="${screenshotHref}" preserveAspectRatio="xMidYMid meet"/>
</svg>\n`;
await fs.writeFile(path.join(import.meta.dirname,'banner.svg'), banner);
for (const [width,height] of [[1544,500],[772,250]]) await sharp(Buffer.from(banner)).resize(width,height).png().toFile(path.join(outputRoot,`banner-${width}x${height}.png`));
console.log(JSON.stringify({outputRoot, screenshotPath, assets:['banner-1544x500.png','banner-772x250.png','icon-256x256.png','icon-128x128.png','icon.svg','../design/banner.svg']}, null, 2));
