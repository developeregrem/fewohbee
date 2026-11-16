import './stimulus_bootstrap.js';
import './js/csrf_protection.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
// Order matters: Bootstrap, then the design tokens and the theme's values, then the bridge that
// points Bootstrap at them, then the application's own styles.
import 'bootstrap/dist/css/bootstrap.min.css';
import './styles/tokens.css';
import './styles/themes/fewohbee.css';
import './styles/bridge.css';
import './styles/shell.css';
import './styles/components.css';
import './styles/app.css';
