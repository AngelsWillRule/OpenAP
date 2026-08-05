import { set_theme } from "../helpers.js";

export function initSystem() {
    console.info("OpenAP System module initialized");

    $('#theme-select').on('change', function() {
        var selectedThemeName = $("#theme-select").val();

        if (selectedThemeName) {
            set_theme(selectedThemeName);
        }
    });
}
