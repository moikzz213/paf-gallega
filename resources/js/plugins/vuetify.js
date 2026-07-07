import 'vuetify/styles';
import '@mdi/font/css/materialdesignicons.css';
import { createVuetify } from 'vuetify';

export default createVuetify({
    theme: {
        defaultTheme: 'light',
        themes: {
            light: {
                colors: {
                    primary: '#256abf',
                    secondary: '#52514e',
                    success: '#008300',
                    warning: '#eda100',
                    error: '#d03b3b',
                    info: '#2a78d6',
                    background: '#f9f9f7',
                    surface: '#fcfcfb',
                },
            },
        },
    },
    defaults: {
        VTextField: { variant: 'outlined', density: 'comfortable' },
        VSelect: { variant: 'outlined', density: 'comfortable' },
        VAutocomplete: { variant: 'outlined', density: 'comfortable' },
        VTextarea: { variant: 'outlined', density: 'comfortable' },
        VFileInput: { variant: 'outlined', density: 'comfortable' },
        VCard: { elevation: 1 },
    },
});
