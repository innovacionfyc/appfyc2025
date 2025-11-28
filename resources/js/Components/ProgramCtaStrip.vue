<template>
    <section class="relative px-4 sm:px-6 lg:px-8 py-16 md:py-1">
        <!-- Contenedor centrado como todas las secciones -->
        <div
            class="relative mx-auto max-w-7xl overflow-hidden rounded-3xl 
                   bg-gradient-to-r from-[#4c1020] via-[#942934] to-[#f39322]
                   shadow-2xl text-mono-blanco">

            <!-- Glow suave -->
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -inset-40 bg-[radial-gradient(circle_at_top,#ffffff22,transparent_60%)]"></div>
            </div>

            <!-- Contenido -->
            <div class="relative z-10 flex flex-col md:flex-row items-center gap-10 md:gap-12 px-10 py-12">

                <!-- Texto -->
                <div class="w-full md:w-7/12 space-y-6">
                    <p
                        class="inline-flex items-center gap-2 rounded-full bg-mono-blanco/10
                               px-4 py-1.5 text-xs font-semibold tracking-[0.18em] uppercase">
                        Programas a la medida
                        <span class="h-1 w-1 rounded-full bg-mono-blanco/70"></span>
                        Sector público
                    </p>

                    <h3 class="text-3xl lg:text-4xl font-extrabold leading-tight">
                        ¿Quieres llevar un programa especializado de F&amp;C a tu entidad?
                    </h3>

                    <p class="text-base text-mono-blanco/85 max-w-xl">
                        Diseñamos experiencias de formación exclusivas para tu entidad:
                        temáticas, intensidad horaria y modalidad se construyen contigo.
                        Nuestro equipo académico y el búho de F&C se encargan del resto.
                    </p>

                    <div class="flex flex-col sm:flex-row gap-4 pt-2">
                        <a
                            href="/contacto"
                            class="inline-flex items-center justify-center gap-2 rounded-xl px-7 py-3.5
                                   font-semibold bg-mono-blanco text-primary-vinotinto
                                   shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all duration-300">
                            Diseñar un programa
                            <span class="text-lg">→</span>
                        </a>

                        <a
                            href="/oferta"
                            class="inline-flex items-center justify-center rounded-xl px-6 py-3.5
                                   font-semibold border border-mono-blanco/45
                                   text-mono-blanco hover:bg-mono-blanco/10
                                   hover:border-mono-blanco/80 transition-all duration-300">
                            Ver líneas de formación
                        </a>
                    </div>

                    <p class="text-xs text-mono-blanco/70 pt-1">
                        También adaptamos congresos y diplomados como eventos privados para tu entidad.
                    </p>
                </div>

                <!-- Búho -->
                <div class="w-full md:w-5/12 flex justify-center md:justify-end">
                    <div class="relative h-56 w-44 sm:h-64 sm:w-52 flex items-center justify-center">

                        <!-- Fondo del búho -->
                        <div
                            class="absolute inset-0 rounded-[2.2rem] bg-mono-blanco/8 backdrop-blur-sm
                                   border border-mono-blanco/15 shadow-[0_18px_40px_rgba(0,0,0,0.25)]">
                        </div>

                        <!-- Imagen -->
                        <img
                            src="/images/aliado-8.png"
                            alt="Búho F&C"
                            class="relative z-10 h-[50%] w-auto drop-shadow-[0_20px_35px_rgba(0,0,0,0.45)]
                                   animate-[floatBuho_6s_ease-in-out_infinite]"
                        >

                        <!-- Sombra -->
                        <div
                            class="pointer-events-none absolute -bottom-4 left-1/2 -translate-x-1/2
                                   h-6 w-24 rounded-full bg-black/35 blur-xl opacity-70">
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>
</template>

<style scoped>
@keyframes floatBuho {
    0%   { transform: translateY(0px); }
    50%  { transform: translateY(-8px); }
    100% { transform: translateY(0px); }
}
</style>
