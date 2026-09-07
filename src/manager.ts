import './assets/manager.css';

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { docReady } from './js/helpers.js';
import FolderFolioManager from './FolderFolioManager.vue';

docReady(function() {
    function createSideForm () {
        const wpBody = document.getElementById('wpbody');
        if (!wpBody) {
            return;
        }
        const sideFormWrapperElement = document.createElement( 'div' );
        sideFormWrapperElement.classList.add('filefolio-wrapper');
        sideFormWrapperElement.innerHTML = '<div id="filefolio-vue-app"></div>';
        wpBody.insertAdjacentElement( 'afterbegin', sideFormWrapperElement );
    }

    createSideForm();

    const app = createApp(FolderFolioManager);

    app.use(createPinia());

    app.mount('#filefolio-vue-app');
});
