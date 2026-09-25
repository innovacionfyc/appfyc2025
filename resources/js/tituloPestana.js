// Nombre de marca fijo para el título de la pestaña: no depende del .env con el que se compile.
export const appName = 'F&C Consultores';

// Título de pestaña: "Página - F&C Consultores". Si la página ya nombra la marca, se respeta tal cual.
export const tituloPestana = (title) => {
    const propio = (title ?? '').trim();

    if (!propio) return appName;

    return /f&c/i.test(propio) ? propio : `${propio} - ${appName}`;
};
