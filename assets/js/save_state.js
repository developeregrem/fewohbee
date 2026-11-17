/**
 * Switches the feedback of <twig:SaveState>: 'saving', 'saved' (fades out by itself),
 * 'failed', or null to show nothing.
 */
export function setSaveState(indicator, state) {
    if (!indicator) {
        return;
    }
    if (state) {
        indicator.dataset.state = state;
    } else {
        delete indicator.dataset.state;
    }
}
