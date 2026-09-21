import './bootstrap';

import Alpine from 'alpinejs';
import recorder from './recorder';
import interview from './interview';

window.Alpine = Alpine;
Alpine.data('recorder', recorder);
Alpine.data('interview', interview);

Alpine.start();
