<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 | f&c consultores</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <style>
        .material-symbols-rounded { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(2deg); }
        }
        @keyframes blob {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -50px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
        }
        .animate-float { animation: float 6s ease-in-out infinite; }
        .animate-blob { animation: blob 7s infinite; }
        .animation-delay-2000 { animation-delay: 2s; }
        .animation-delay-4000 { animation-delay: 4s; }
    </style>
</head>
<body class="bg-slate-50 antialiased overflow-hidden">
    <div class="fixed inset-0 z-0">
        <div class="absolute top-0 -left-4 w-72 h-72 bg-rose-400 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob"></div>
        <div class="absolute top-0 -right-4 w-72 h-72 bg-red-400 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-2000"></div>
        <div class="absolute -bottom-8 left-20 w-72 h-72 bg-pink-400 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-4000"></div>
    </div>

    <main class="relative z-10 min-h-dvh flex items-center justify-center px-6">
        <div class="max-w-2xl w-full">
            <div class="bg-white/70 backdrop-blur-2xl rounded-[3rem] p-10 md:p-20 shadow-[0_32px_64px_-15px_rgba(0,0,0,0.1)] border border-white text-center">
                
                <div class="relative inline-block mb-8">
                    <span class="text-[8rem] md:text-[12rem] font-black text-slate-900/5 leading-none select-none">404</span>
                    <div class="absolute inset-0 flex items-center justify-center animate-float">
                        <span class="material-symbols-rounded text-rose-600 text-7xl md:text-8xl shadow-2xl bg-white rounded-3xl p-4">
                            explore_off
                        </span>
                    </div>
                </div>

                <div class="space-y-6">
                    <p class="text-xs font-black text-rose-600 uppercase tracking-[0.4em]">ruta no encontrada</p>
                    
                    <h1 class="text-4xl md:text-5xl font-black text-slate-900 leading-tight ">
                        Parece que esta página <br/> <span class="text-rose-600 italic">No existe</span>
                    </h1>
                    
                    <p class="text-slate-500 text-base md:text-lg max-w-sm mx-auto  leading-relaxed">
                        Lo sentimos pero el enlace que sigues está roto o la dirección ha sido cambiada por mantenimiento
                    </p>

                    <div class="pt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                        <a href="/" class="group w-full sm:w-auto bg-slate-900 text-white px-10 py-5 rounded-2xl font-black text-sm uppercase tracking-widest hover:bg-rose-600 transition-all duration-500 hover:shadow-[0_20px_40px_-10px_rgba(37,99,235,0.5)] flex items-center justify-center gap-3">
                            Ir al inicio
                            <span class="group-hover:translate-x-1 transition-transform">→</span>
                        </a>
                        
                        <a href="https://wa.me/573163861932" target="_blank" class="w-full sm:w-auto bg-white text-slate-900 px-10 py-5 rounded-2xl font-black text-sm border border-slate-200 hover:border-slate-900 transition-all duration-300 lowercase">
                            Ayuda técnica
                        </a>
                    </div>
                </div>
            </div>

            <div class="mt-12 flex flex-col items-center gap-3 opacity-60">
                <div class="flex items-center gap-2">
                    <span class="text-[10px] font-medium text-slate-400 lowercase">hecho con</span>
                    <span class="material-symbols-rounded text-red-500 text-xs animate-pulse">favorite</span>
                    <span class="text-[10px] font-medium text-slate-400 lowercase">
                        por el equipo <span class="text-slate-900 font-bold">transformación digital y comunicaciones</span>
                    </span>
                </div>
                <p class="text-[9px] font-black text-slate-300 uppercase tracking-[0.5em]">F&C consultores</p>
            </div>
        </div>
    </main>
</body>
</html>