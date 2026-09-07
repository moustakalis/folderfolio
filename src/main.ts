import './assets/main.css';

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import FolderFolioApp from './FolderFolioApp.vue';

const app = createApp(FolderFolioApp);

app.use(createPinia());

app.mount('#app');
