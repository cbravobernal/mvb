import { store } from '@wordpress/interactivity';

store('mvb', {
    actions: {
        setSearch() {
            console.log('setSearch');
        },
    },
});