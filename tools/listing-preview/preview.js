/* Local preview only. No network requests, storage, build or publishing hooks. */
const tabs = [...document.querySelectorAll('.tab-links a')];
const panels = [...document.querySelectorAll('.panel')];

function showSection() {
    const hash = window.location.hash || '#description';
    const selected = panels.some((panel) => '#' + panel.id === hash) ? hash : '#description';
    for (const panel of panels) panel.hidden = '#' + panel.id !== selected;
    for (const tab of tabs) {
        if (tab.hash === selected) tab.setAttribute('aria-current', 'page');
        else tab.removeAttribute('aria-current');
    }
}
showSection();
window.addEventListener('hashchange', showSection);
document.querySelector('#refresh').addEventListener('click', () => window.location.reload());

const imageDialog = document.querySelector('#image-dialog');
const enlargedImage = document.querySelector('#enlarged-image');
for (const link of document.querySelectorAll('.screenshot')) {
    link.addEventListener('click', (event) => {
        event.preventDefault();
        const image = link.querySelector('img');
        enlargedImage.src = link.href;
        enlargedImage.alt = image.alt;
        document.querySelector('#image-caption').textContent = image.alt;
        imageDialog.showModal();
    });
}
document.querySelector('#download').addEventListener('click', () => document.querySelector('#download-dialog').showModal());
for (const dialog of document.querySelectorAll('dialog')) {
    dialog.querySelector('.close-dialog').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const box = dialog.getBoundingClientRect();
        if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close();
    });
}
