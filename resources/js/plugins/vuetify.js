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
        // autocomplete off across all inputs; components can override (e.g. the login form).
        VForm: { autocomplete: 'off' },
        VTextField: { variant: 'outlined', density: 'comfortable', autocomplete: 'off' },
        VSelect: { variant: 'outlined', density: 'comfortable', autocomplete: 'off' },
        VAutocomplete: { variant: 'outlined', density: 'comfortable', autocomplete: 'off' },
        VTextarea: { variant: 'outlined', density: 'comfortable', autocomplete: 'off' },
        VFileInput: { variant: 'outlined', density: 'comfortable', autocomplete: 'off' },
        VCard: { elevation: 1 },
    },
});
