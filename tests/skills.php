<?php
/**
 * Offline fixed-skill registry, package boundaries and actual native block examples.
 * No wp-load.php, database, HTTP, AI, browser or site mutations.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/skills.php
 * Uses the installed WordPress JS bundles and the existing local JSDOM package.
 */
$core_root = getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Studio/bhranti-2';
define( 'ABSPATH', rtrim( $core_root, '/' ) . '/' );
require ABSPATH . 'wp-includes/class-wp-error.php';
function __( $text, $domain = 'default' ) { return $text; }
function do_action( ...$args ) {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_request() { throw new RuntimeException( 'HTTP forbidden.' ); }
function wp_ai_client_prompt() { throw new RuntimeException( 'AI forbidden.' ); }
require dirname( __DIR__ ) . '/includes/class-bhranti-knowledge.php';
$checks = 0;
function skill_check( $value, $message ) {
	if ( ! $value ) { throw new RuntimeException( $message ); }
	++$GLOBALS['checks'];
}
function skill_error( $value, $code, $message ) {
	skill_check( is_wp_error( $value ) && $code === $value->get_error_code(), $message );
	skill_check( false === strpos( $value->get_error_message(), '/private/' ) && false === strpos( $value->get_error_message(), 'SKILL.md' ), 'Errors do not disclose package paths.' );
}
skill_error( Bhranti_Knowledge::design_skill(), 'bhranti_skill_unavailable', 'A missing registry class fails closed rather than dispatching without baseline instructions.' );
require dirname( __DIR__ ) . '/includes/class-bhranti-skills.php';
$names = array( 'site-design', 'design-composition', 'wordpress-support', 'content-operations', 'commerce-workflow' );
$available = Bhranti_Skills::available();
skill_check( $names === array_keys( $available ), 'Exactly the fixed registered expertise names are available.' );
$tool = Bhranti_Skills::catalog()['bhranti_skill'];
skill_check( array( 'name' ) === $tool['parameters']['required'] && false === $tool['parameters']['additionalProperties'], 'The tool requires only the exact name and rejects filesystem/extra arguments.' );
skill_check( $names === $tool['parameters']['properties']['name']['enum'], 'The strict native schema names exactly the fixed registry.' );
skill_check( false === strpos( json_encode( array( $available, $tool ) ), 'SKILL.md' ), 'Discovery exposes purpose and names without internal paths.' );
$examples = array();
foreach ( $available as $name => $description ) {
	$document = Bhranti_Skills::read( $name );
	skill_check( is_array( $document ) && array( 'name', 'description', 'content', 'revision' ) === array_keys( $document ), 'A registered read returns only bounded document metadata.' );
	skill_check( $name === $document['name'] && $description === $document['description'], 'Read metadata matches the exact registered name and purpose.' );
	skill_check( is_string( $description ) && '' !== trim( $description ) && strlen( $description ) <= 200, 'Each discovery description is short and nonempty.' );
	skill_check( '' !== trim( $document['content'] ) && strlen( $document['content'] ) <= Bhranti_Skills::LIMIT, 'Every shipped skill fits the hard package byte limit.' );
	skill_check( 0 === strpos( $document['content'], "---\nname: " . $name . "\n" ) && false !== strpos( $document['content'], "license: GPL-2.0-or-later\n" ), 'Each original packaged document names itself and its GPL-compatible license.' );
	skill_check( hash( 'sha256', $document['content'] ) === $document['revision'], 'Revision identifies the exact bytes returned to the model.' );
	skill_check( $document === Bhranti_Skills::read( $name ), 'A repeat read has stable document identity without site side effects.' );
	preg_match_all( '/```html\n([\s\S]*?)\n```/', $document['content'], $matches );
	foreach ( $matches[1] as $markup ) { $examples[] = array( 'skill' => $name, 'markup' => $markup ); }
}
skill_check( Bhranti_Skills::read( 'site-design' )['content'] === Bhranti_Knowledge::design_skill(), 'The mandatory baseline retains Knowledge’s full-text contract.' );
skill_check( strlen( Bhranti_Knowledge::design_skill() ) < 9000, 'The mandatory baseline remains concise instead of adding another large prompt.' );
skill_check( false !== strpos( Bhranti_Knowledge::design_skill(), 'all new or changed content' ) && false !== strpos( Bhranti_Knowledge::design_skill(), 'WCAG 2.2 A and AA' ), 'The always-loaded baseline retains all-authored-content accessibility requirements.' );
skill_check( false !== strpos( Bhranti_Knowledge::design_skill(), 'Wait for actual answers before any mutation' ) && false !== strpos( Bhranti_Knowledge::design_skill(), 'focused change' ), 'Broad intake and direct focused edits remain distinct baseline rules.' );
skill_check( false !== strpos( Bhranti_Knowledge::instructions(), 'There is no GET /wp/v2/global-styles collection' ), 'Universal grounding retains the existing exact global-style discovery contract.' );
$content_guidance = Bhranti_Skills::read( 'content-operations' )['content'];
foreach ( array( 'core/image', 'core/cover', 'core/media-text', 'core/gallery', 'featured_media' ) as $role ) {
	skill_check( false !== strpos( $content_guidance, $role ), 'Native imagery guidance includes the distinct role: ' . $role );
}
skill_check( false !== strpos( $content_guidance, '"media_type":["image"]' ) && false !== strpos( $content_guidance, 'media_details.width,media_details.height,media_details.sizes' ), 'The media read recipe uses current native array filters and inspectable dimensions/sizes.' );
skill_check( false !== strpos( $content_guidance, 'inert teaching placeholders' ) && false !== strpos( $content_guidance, 'authentic approved' ) && false !== strpos( $content_guidance, 'nonessential decorative assets' ), 'Examples do not claim real attachment identity or require unnecessary asset questions.' );
foreach ( array( '', 'SITE-DESIGN', ' site-design', 'site-design ', '../site-design', '/skills/site-design/SKILL.md', 'site-design/SKILL.md', 'https://example.com/skill', 'user-configured', "%2e%2e/site-design", "site-design\0", null, 0, true, array( 'name' => 'site-design' ) ) as $name ) {
	skill_error( Bhranti_Skills::read( $name ), 'bhranti_skill_unknown', 'Unregistered names and caller paths/types cannot load any file.' );
}

// Boundary fixtures exercise the internal bounded loader without renaming a package or changing a site.
$reader = new ReflectionMethod( Bhranti_Skills::class, 'read_text' );
$temporary = tempnam( sys_get_temp_dir(), 'bhranti-skills-' );
try {
	foreach ( array( '', " \t\r\n", str_repeat( 'x', Bhranti_Skills::LIMIT + 1 ) ) as $text ) {
		file_put_contents( $temporary, $text ); clearstatcache( true, $temporary );
		skill_error( $reader->invoke( null, $temporary ), 'bhranti_skill_unavailable', 'Empty/whitespace/oversized package text fails closed.' );
	}
	$text = str_repeat( 'x', Bhranti_Skills::LIMIT );
	file_put_contents( $temporary, $text ); clearstatcache( true, $temporary );
	skill_check( $text === $reader->invoke( null, $temporary ), 'Exactly the byte limit is accepted without truncation.' );
	$text = str_repeat( '猫', intdiv( Bhranti_Skills::LIMIT, 3 ) + 1 );
	file_put_contents( $temporary, $text ); clearstatcache( true, $temporary );
	skill_error( $reader->invoke( null, $temporary ), 'bhranti_skill_unavailable', 'Multibyte text is bounded by bytes rather than character count.' );
	$text = '<?php $GLOBALS["bhranti_skill_executed"] = true; ?>Literal data';
	file_put_contents( $temporary, $text ); clearstatcache( true, $temporary );
	skill_check( $text === $reader->invoke( null, $temporary ) && ! isset( $GLOBALS['bhranti_skill_executed'] ), 'Markdown/text is read literally and never included or executed.' );
	unlink( $temporary ); clearstatcache( true, $temporary );
	skill_error( $reader->invoke( null, $temporary ), 'bhranti_skill_unavailable', 'Missing package text fails closed.' );
	skill_error( $reader->invoke( null, dirname( $temporary ) ), 'bhranti_skill_unavailable', 'Directories cannot become skill documents.' );
} finally {
	if ( is_file( $temporary ) ) { unlink( $temporary ); }
}

// Native JS save/parse evidence is required for every HTML example, not PHP block parsing alone.
skill_check( count( $examples ) >= 5, 'The native gallery exercises Cover and scoped adjoining sections alongside basic content blocks.' );
$script = <<<'JS'
'use strict';
const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path');
let JSDOM;
try { ({ JSDOM } = require('jsdom')); }
catch { ({ JSDOM } = require(process.env.BHRANTI_TEST_JSDOM_PATH || '/Users/txsadhu/node_modules/jsdom')); }
const root = process.argv[2], examples = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
const packages = fs.readFileSync(path.join(root, 'wp-includes/assets/script-loader-packages.php'), 'utf8'), dependencies = new Map();
for (const match of packages.matchAll(/\t'([^']+\.js)' => array\(\n\t\t'dependencies' => array\(([\s\S]*?)\n\t\t\)/g)) {
  dependencies.set('wp-' + match[1].slice(0, -3), [...match[2].matchAll(/'([^']+)'/g)].map((item) => item[1]));
}
const vendors = new Map([['react', []], ['react-dom', ['react']], ['react-jsx-runtime', ['react']], ['moment', []], ['lodash', []], ['regenerator-runtime', []], ['wp-polyfill', []]]);
const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://offline.invalid/', runScripts: 'dangerously', pretendToBeVisual: true });
const w = dom.window, seen = new Set(), visiting = new Set(); let httpCalls = 0, count = 0;
w.fetch = () => { httpCalls++; throw new Error('HTTP forbidden in the native skill fixture.'); };
w.XMLHttpRequest = function() { httpCalls++; throw new Error('XHR forbidden in the native skill fixture.'); };
w.matchMedia = (media) => ({ media, matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} });
w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
w.TextEncoder = TextEncoder; w.TextDecoder = TextDecoder;
function load(handle) {
  if (seen.has(handle)) return;
  assert.ok(!visiting.has(handle), 'Native dependencies are acyclic: ' + handle); visiting.add(handle);
  const vendor = vendors.has(handle), deps = vendor ? vendors.get(handle) : dependencies.get(handle);
  assert.ok(deps, 'A native registered dependency exists: ' + handle); deps.forEach(load);
  const filename = path.join(root, 'wp-includes/js/dist', vendor ? 'vendor/' + handle + '.js' : handle.slice(3) + '.js');
  let error; const capture = (event) => { error = event.error; event.preventDefault(); }; w.addEventListener('error', capture);
  const script = w.document.createElement('script'); script.textContent = fs.readFileSync(filename, 'utf8'); w.document.head.appendChild(script);
  w.removeEventListener('error', capture); if (error) throw error;
  if (handle === 'lodash') w.lodash = w._;
  visiting.delete(handle); seen.add(handle);
}
function validate(blocks, label) {
  for (const block of blocks) {
    assert.ok(block.isValid, 'Native save validation passes for ' + label + ': ' + block.name);
    assert.notEqual(block.name, 'core/freeform', 'Examples remain real native blocks');
    count++; validate(block.innerBlocks, label);
  }
}
try {
  load('wp-block-library'); w.wp.blockLibrary.registerCoreBlocks();
  for (const [name, attributes] of Object.entries({
    'core/cover': ['id', 'url', 'dimRatio', 'customOverlayColor', 'focalPoint', 'minHeight'],
    'core/image': ['id', 'url', 'alt', 'aspectRatio'],
    'core/media-text': ['mediaId', 'mediaUrl', 'mediaType', 'mediaAlt', 'isStackedOnMobile'],
    'core/gallery': ['columns', 'imageCrop'],
    'core/group': ['style', 'layout']
  })) {
    const type = w.wp.blocks.getBlockType(name); assert.ok(type, 'The described image/layout role exists in installed core');
    for (const attribute of attributes) assert.ok(type.attributes[attribute], 'Guidance uses an actual registered attribute: ' + name + '.' + attribute);
  }
  let coverExamples = 0, adjoiningExamples = 0;
  for (const example of examples) {
    const blocks = w.wp.blocks.parse(example.markup); assert.ok(blocks.length, 'Example contains native blocks'); validate(blocks, example.skill);
    for (const block of blocks) {
      if (block.name === 'core/cover') {
        coverExamples++;
        assert.ok(Number.isInteger(block.attributes.id) && block.attributes.id > 0, 'Cover uses a native attachment identity');
        assert.ok(block.attributes.dimRatio > 0 && block.attributes.dimRatio <= 100, 'Cover overlay uses a native opacity range');
        assert.ok(block.attributes.customOverlayColor && block.attributes.focalPoint.x >= 0 && block.attributes.focalPoint.x <= 1 && block.attributes.focalPoint.y >= 0 && block.attributes.focalPoint.y <= 1, 'Cover retains its overlay and normalized crop focal point');
        const fragment = w.document.createElement('template'); fragment.innerHTML = w.wp.blocks.getSaveContent(block.name, block.attributes, block.innerBlocks);
        const image = fragment.content.querySelector('img.wp-block-cover__image-background');
        assert.ok(image && image.getAttribute('src') === block.attributes.url && image.getAttribute('alt') === '', 'Native Cover save retains the decorative asset URL and empty alt');
        assert.ok(fragment.content.querySelector('span[aria-hidden="true"].wp-block-cover__background'), 'Cover native background overlay remains decorative');
      }
      if (block.name === 'core/group' && block.attributes.style?.spacing?.blockGap === '0') {
        adjoiningExamples++;
        assert.equal(block.attributes.align, 'full', 'The zero gap is scoped to an adjoining section wrapper');
        assert.equal(block.innerBlocks.length, 2, 'The adjoining surfaces remain separate native sections');
        for (const section of block.innerBlocks) {
          assert.equal(section.name, 'core/group'); assert.equal(section.attributes.align, 'full');
          assert.equal(section.attributes.style.spacing.blockGap, '16px', 'Parent zero gap preserves each section’s internal rhythm');
          assert.ok(section.attributes.style.spacing.padding.top && section.attributes.style.spacing.padding.bottom, 'Each section keeps intentional padding');
        }
      }
    }
    const canonical = w.wp.blocks.serialize(blocks); assert.equal(canonical.trim(), example.markup.trim(), 'Gallery markup is current native save output, without automatic deprecated-block migration');
    const reparsed = w.wp.blocks.parse(canonical); validate(reparsed, example.skill + ' round trip');
    assert.equal(reparsed.length, blocks.length, 'Native round trip preserves block count');
    assert.equal(w.wp.blocks.serialize(reparsed), canonical, 'Canonical native saved markup is stable');
  }
  assert.ok(coverExamples && adjoiningExamples, 'Both new native recipes are validated, not just described');
  assert.equal(httpCalls, 0, 'Native example validation makes no HTTP calls');
  process.stdout.write('Native skill gallery: ' + examples.length + ' examples, ' + count + ' valid block checks, no HTTP.\n');
} finally { dom.window.close(); }
JS;
$script_file = tempnam( sys_get_temp_dir(), 'bhranti-skill-native-' );
$examples_file = tempnam( sys_get_temp_dir(), 'bhranti-skill-examples-' );
try {
	file_put_contents( $script_file, $script ); file_put_contents( $examples_file, json_encode( $examples ) );
	$node = getenv( 'BHRANTI_TEST_NODE_PATH' ) ?: '/opt/homebrew/bin/node';
	$process = proc_open( array( $node, $script_file, $core_root, $examples_file ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	skill_check( is_resource( $process ), 'Native JS validation process starts.' );
	fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
	$status = proc_close( $process );
	skill_check( 0 === $status, 'Actual installed native block examples must validate: ' . $stderr );
	echo $stdout;
} finally {
	unlink( $script_file ); unlink( $examples_file );
}
echo 'Built-in skills: ' . $checks . " checks passed; no site/AI/network operations.\n";
