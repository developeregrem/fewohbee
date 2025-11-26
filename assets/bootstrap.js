import { startStimulusApp } from '@symfony/stimulus-bundle';
import LoginController from './controllers/login_controller.js';

const app = startStimulusApp();
app.register('login', LoginController);
