const Ziggy = {
    url: "http://localhost",
    port: null,
    defaults: {},
    routes: {
        "sanctum.csrf-cookie": {
            uri: "sanctum/csrf-cookie",
            methods: ["GET", "HEAD"],
        },
        "aplicacion.configuraciones": {
            uri: "{aplicacion}/{rol}/configuraciones",
            methods: ["GET", "HEAD"],
            parameters: ["aplicacion", "rol"],
        },
        "essentials.admin.crearItem": {
            uri: "essentials/admin/crearItem",
            methods: ["GET", "HEAD"],
        },
        "aplicacion.dashboard": {
            uri: "{aplicacion}/{rol}/dashboard",
            methods: ["GET", "HEAD"],
            parameters: ["aplicacion", "rol"],
        },
        "aplicacion.codigoBarras": {
            uri: "{aplicacion}/{rol}/codigoBarras",
            methods: ["GET", "HEAD"],
            parameters: ["aplicacion", "rol"],
        },
        "aplicacion.gastos": { uri: "gastos", methods: ["GET", "HEAD"] },
        "aplicacion.generadorQrs": {
            uri: "{aplicacion}/{rol}/generadorQrs",
            methods: ["GET", "HEAD"],
            parameters: ["aplicacion", "rol"],
        },
        "aplicacion.inventarios": {
            uri: "{aplicacion}/{rol}/inventarios",
            methods: ["GET", "HEAD"],
            parameters: ["aplicacion", "rol"],
        },
        "aplicacion.multisucursales": {
            uri: "{aplicacion}/{rol}/multisucursales",
            methods: ["GET", "HEAD"],
            parameters: ["aplicacion", "rol"],
        },
        "aplicacion.ordenTrabajos": {
            uri: "{aplicacion}/{rol}/ordenTrabajos",
            methods: ["GET", "HEAD"],
            parameters: ["aplicacion", "rol"],
        },
        "aplicacion.overviews": {
            uri: "{aplicacion}/{rol}/overviews",
            methods: ["GET", "HEAD"],
            parameters: ["aplicacion", "rol"],
        },
        "aplicacion.resenas": {
            uri: "{aplicacion}/{rol}/resenas",
            methods: ["GET", "HEAD"],
            parameters: ["aplicacion", "rol"],
        },
        "aplicacion.reservas": {
            uri: "{aplicacion}/{rol}/reservas",
            methods: ["GET", "HEAD"],
            parameters: ["aplicacion", "rol"],
        },
        "aplicacion.usuarios": {
            uri: "{aplicacion}/{rol}/usuarios",
            methods: ["GET", "HEAD"],
            parameters: ["aplicacion", "rol"],
        },
        "essentials.cliente.dashboard": {
            uri: "essentials/cliente/dashboard",
            methods: ["GET", "HEAD"],
        },
        swap: { uri: "swap", methods: ["GET", "HEAD"] },
        "essentials.empleado.dashboard": {
            uri: "essentials/empleado/dashboard",
            methods: ["GET", "HEAD"],
        },
        "guru.admin.dashboard": {
            uri: "guru/admin/dashboard",
            methods: ["GET", "HEAD"],
        },
        "cliente.dashboard": {
            uri: "smart/cliente/dashboard",
            methods: ["GET", "HEAD"],
        },
        "empleado.dashboard": {
            uri: "smart/empleado/dashboard",
            methods: ["GET", "HEAD"],
        },
        "admin.dashboard": {
            uri: "smart/admin/dashboard",
            methods: ["GET", "HEAD"],
        },
        "aplicacion.dashboard.detalle": {
            uri: "{aplicacion}/{rol}/dashboard/detalle/{idCliente}",
            methods: ["GET", "HEAD"],
            parameters: ["aplicacion", "rol", "idCliente"],
        },
        "clientes-activacion": {
            uri: "{aplicacion}/{rol}/clientes-activacion",
            methods: ["GET", "HEAD"],
            parameters: ["aplicacion", "rol"],
        },
        "clientes.destroy": {
            uri: "{aplicacion}/{rol}/clientes/{id}",
            methods: ["DELETE"],
            parameters: ["aplicacion", "rol", "id"],
        },
        "login.auth": { uri: "login/auth", methods: ["GET", "HEAD"] },
        "login.post": { uri: "login/auth", methods: ["POST"] },
        logout: { uri: "login/logout", methods: ["POST"] },
        "register.auth": { uri: "register/auth", methods: ["GET", "HEAD"] },
        "register.post": { uri: "register/auth", methods: ["POST"] },
        "home.index": { uri: "/", methods: ["GET", "HEAD"] },
        login: { uri: "login", methods: ["GET", "HEAD"] },
        "storage.local": {
            uri: "storage/{path}",
            methods: ["GET", "HEAD"],
            wheres: { path: ".*" },
            parameters: ["path"],
        },
    },
};
if (typeof window !== "undefined" && typeof window.Ziggy !== "undefined") {
    Object.assign(Ziggy.routes, window.Ziggy.routes);
}
export { Ziggy };
