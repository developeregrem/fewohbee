// Bootstrap's data attributes (data-bs-toggle …) work once the module is loaded
import 'bootstrap';
import './stimulus_bootstrap.js';
import './js/csrf_protection.js';
// Order matters: Bootstrap, then the design tokens and the theme's values, then the bridge that
// points Bootstrap at them, then the application's own styles from general to specific. A new
// view gets its own file in styles/views/, a new building block one in styles/components/.
import 'bootstrap/dist/css/bootstrap.min.css';
import './styles/tokens.css';
import './styles/themes/fewohbee.css';
import './styles/bridge.css';
import './styles/base.css';
import './styles/shell.css';
import './styles/components/records.css';
import './styles/components/controls.css';
import './styles/components/forms.css';
import './styles/components/dialogs.css';
import './styles/components/touch.css';
import './styles/components/autocomplete.css';
import './styles/views/reservations.css';
import './styles/views/frontdesk.css';
import './styles/views/invoices.css';
import './styles/views/booking-journal.css';
import './styles/views/prices.css';
import './styles/views/statistics.css';
import './styles/views/booking-rules.css';
import './styles/views/template-editor.css';
import './styles/views/settings.css';
import './styles/views/release-notes.css';
import './styles/views/styleguide.css';
