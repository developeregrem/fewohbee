import { Controller } from '@hotwired/stimulus';
import { enableDeletePopover, enableTooltips, disposeTooltips } from '../js/utils.js';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    connect() {
        enableDeletePopover({ root: this.element });
        enableTooltips(this.element);
    }

    disconnect() {
        disposeTooltips(this.element);
    }
}
