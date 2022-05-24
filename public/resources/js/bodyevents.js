/**
 * Enable click event for copy-to-clipboard function
 */
delegate(document.querySelector('#modal-content-ajax'), 'click', '.copy-to-clipboard', (e) => {
    copyToClipboard(e.target.closest('.copy-to-clipboard'));
});