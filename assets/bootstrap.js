import { startStimulusApp } from '@symfony/stimulus-bundle';
import LoginController from './controllers/login_controller.js';
import ProfileFormController from './controllers/profile_form_controller.js';
import CopyController from './controllers/copy_controller.js';
import ReservationsController from './controllers/reservations_controller.js';
import InvoicesController from './controllers/invoices_controller.js';
import CustomersController from './controllers/customers_controller.js';
import CustomerFormController from './controllers/customer_form_controller.js';
import RegistrationbookController from './controllers/registrationbook_controller.js';

const app = startStimulusApp();
app.register('login', LoginController);
app.register('profile-form', ProfileFormController);
app.register('copy', CopyController);
app.register('reservations', ReservationsController);
app.register('invoices', InvoicesController);
app.register('customers', CustomersController);
app.register('customer-form', CustomerFormController);
app.register('registrationbook', RegistrationbookController);
