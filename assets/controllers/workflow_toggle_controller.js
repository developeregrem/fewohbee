import { Controller } from '@hotwired/stimulus';
import { request } from '../js/http.js';
import { setSaveState } from '../js/save_state.js';

/**
 * Switches a workflow on or off as soon as its switch changes. The controller sits on the switch
 * itself or on an element around it that holds the switch as target "input"; an optional
 * <twig:SaveState> (target "state") shows saving, saved or failed. The switch follows what the
 * server stored and goes back when the request fails.
 */
export default class extends Controller {
    static targets = ['input', 'state'];
    static values = {
        url: String,
        token: String,
    };

    toggle() {
        const input = this.hasInputTarget ? this.inputTarget : this.element;
        const state = this.hasStateTarget ? this.stateTarget : null;
        setSaveState(state, 'saving');
        request({
            url: this.urlValue,
            method: 'POST',
            data: { _token: this.tokenValue },
            loader: false,
            onSuccess: (text) => {
                input.checked = Boolean(JSON.parse(text).enabled);
                setSaveState(state, 'saved');
            },
            onError: () => {
                input.checked = !input.checked;
                setSaveState(state, 'failed');
            },
        });
    }
}
