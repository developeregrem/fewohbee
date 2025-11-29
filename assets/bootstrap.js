import { startStimulusApp } from '@symfony/stimulus-bundle';
import LoginController from './controllers/login_controller.js';
import ProfileFormController from './controllers/profile_form_controller.js';

const app = startStimulusApp();
app.register('login', LoginController);
app.register('profile-form', ProfileFormController);
