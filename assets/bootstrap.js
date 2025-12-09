import { startStimulusApp } from '@symfony/stimulus-bundle';
import LoginController from './controllers/login_controller.js';
import ProfileFormController from './controllers/profile_form_controller.js';
import CopyController from './controllers/copy_controller.js';
import ReservationsController from './controllers/reservations_controller.js';
import InvoicesController from './controllers/invoices_controller.js';

const app = startStimulusApp();
app.register('login', LoginController);
app.register('profile-form', ProfileFormController);
app.register('copy', CopyController);
app.register('reservations', ReservationsController);
app.register('invoices', InvoicesController);
