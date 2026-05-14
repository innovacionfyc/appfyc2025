<script setup>
import { ref, onMounted } from "vue";
import HeaderNav from "@/Components/HeaderNav.vue";
import RevealSection from "@/Components/RevealSection.vue";
import SiteFooter from "@/Components/SiteFooter.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";

// Navegación lateral dinámica
const sections = [
  { id: "generalidades", label: "1. Generalidades" },
  { id: "finalidad", label: "2. Finalidad" },
  { id: "seguridad", label: "3. Seguridad" },
  { id: "almacenamiento", label: "4. Almacenamiento" },
  { id: "uso", label: "5. Uso de Información" },
  { id: "derechos", label: "7. Derechos" },
  { id: "atencion", label: "9. Atención al Cliente" },
  { id: "manual-interno", label: "Manual Interno" },
];

const scrollTo = (id) => {
  const el = document.getElementById(id);
  if (el) {
    window.scrollTo({
      top: el.offsetTop - 100,
      behavior: "smooth",
    });
  }
};
</script>

<template>
  <GuestLayout>
    <div class="min-h-screen bg-white">
      <HeaderNav />

      <main class="max-w-7xl mx-auto px-6 lg:px-8 pt-32 pb-20">
        <div class="flex flex-col lg:flex-row gap-12">
          <aside class="hidden lg:block lg:w-1/4">
            <div class="sticky top-32 space-y-2 border-l border-slate-200 pl-6">
              <p class="text-xs font-black uppercase text-slate-400 mb-4 tracking-widest">
                Contenido
              </p>
              <button
                v-for="section in sections"
                :key="section.id"
                @click="scrollTo(section.id)"
                class="block text-left text-sm font-medium text-slate-500 hover:text-rose-600 hover:translate-x-1 transition-all duration-200 py-1"
              >
                {{ section.label }}
              </button>

              <div class="mt-8 pt-8 border-t border-slate-100">
                <a
                  href="#"
                  class="flex items-center gap-2 text-xs font-bold text-slate-900 group"
                >
                  <svg
                    class="w-4 h-4 group-hover:animate-bounce"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
                      stroke-width="2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                  DESCARGAR PDF
                </a>
              </div>
            </div>
          </aside>

          <div class="lg:w-3/4">
           <RevealSection class="flex-grow flex items-center min-h-dvh snap-start w-full">
              <div class="prose prose-slate max-w-none">
                <h1
                  class="text-3xl md:text-5xl font-black text-slate-900 mb-4 uppercase leading-tight"
                >
                  Política para el Tratamiento de Datos Personales
                </h1>
                <p
                  class="text-rose-600 font-bold mb-12 flex items-center gap-2 text-sm md:text-base"
                >
                  <span class="w-8 h-px bg-rose-600"></span> F&C CONSULTORES S.A.S.
                </p>

                <section id="generalidades" class="mb-16 scroll-mt-32">
                  <h2
                    class="text-2xl font-bold text-slate-900 mb-6 flex items-center gap-3"
                  >
                    <span class="text-slate-300">01</span> GENERALIDADES
                  </h2>
                  <div class="space-y-4 text-slate-700 leading-relaxed text-justify">
                    <p>
                      F&C CONSULTORES S.A.S, sociedad comercial legalmente constituida,
                      con domicilio en Bogotá, es la sociedad responsable de sus datos
                      personales, los cuales ha recolectado a través del desarrollo de su
                      actividad comercial.
                    </p>
                    <p>
                      Para los propósitos de la presente Política, las personas que
                      integran la base de datos se denominarán <b>CLIENTES</b>.
                    </p>

                    <div
                      class="bg-slate-50 p-6 rounded-2xl border-l-4 border-slate-900 my-8"
                    >
                      <p class="text-sm font-bold uppercase mb-2 text-slate-500 italic">
                        Artículo 15 - Constitución Política:
                      </p>
                      <p class="text-sm italic">
                        “Todas las personas tienen derecho a su intimidad personal y
                        familiar y a su buen nombre, y el Estado debe respetarlos y
                        hacerlos respetar. De igual modo, tienen derecho a conocer,
                        actualizar y rectificar las informaciones que se hayan recogido
                        sobre ellas...”
                      </p>
                    </div>

                    <p>
                      La Ley Estatutaria 1581 de 2012 y el Decreto 1377 de 2013 son los
                      ejes rectores de este documento.
                    </p>
                  </div>
                </section>

                <hr class="border-slate-100 my-12" />

                <!-- 2. FINALIDAD -->
                <section id="finalidad" class="mb-16 scroll-mt-32">
                  <h2
                    class="text-2xl font-bold text-slate-900 mb-6 flex items-center gap-3"
                  >
                    <span class="text-slate-300">02</span> FINALIDAD DEL TRATAMIENTO
                  </h2>
                  <p class="text-slate-700 leading-relaxed text-justify">
                    La información proporcionada por nuestros clientes se requiere para
                    ser almacenada, organizada y usada a fin de mantenerlo informado
                    acerca de la celebración de <b>eventos académicos</b> que tienen
                    relación directa con la actividad profesional que usted realiza, así
                    como para remitirle periódicamente <b>información jurídica</b> de su
                    especial interés.
                  </p>
                </section>

                <!-- 3. SEGURIDAD -->
                <section id="seguridad" class="mb-16 scroll-mt-32">
                  <div
                    class="bg-primary-vinotinto text-white p-8 md:p-12 rounded-[2.5rem] shadow-xl"
                  >
                    <h2 class="text-2xl font-bold mb-6">
                      3. SEGURIDAD DE LA INFORMACIÓN
                    </h2>
                    <div class="space-y-4 text-rose-50 opacity-90 text-sm md:text-base">
                      <p>
                        F&C Consultores SAS asume el compromiso de proteger a sus Clientes
                        del acceso no autorizado o cualquier modificación, divulgación o
                        destrucción no autorizada.
                      </p>
                      <ul class="list-disc pl-5 space-y-2">
                        <li>Posee controles estrictos sobre sistemas de información.</li>
                        <li>
                          Revisa prácticas de recopilación y almacenamiento físicamente
                          protegidas.
                        </li>
                        <li>
                          Restringe el acceso solo a empleados con acuerdos de
                          confidencialidad y responsabilidad civil/penal.
                        </li>
                      </ul>
                    </div>
                  </div>
                </section>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-16">
                  <section
                    id="almacenamiento"
                    class="p-8 bg-slate-50 rounded-3xl scroll-mt-32"
                  >
                    <h3 class="font-bold text-slate-900 mb-4 italic">
                      4. Almacenamiento
                    </h3>
                    <p class="text-sm text-slate-600">
                      Datos montados sobre plataforma CRM (Vtiger). Servidor protegido
                      físicamente en lugar seguro con acceso restringido.
                    </p>
                  </section>
                  <section id="uso" class="p-8 bg-slate-50 rounded-3xl scroll-mt-32">
                    <h3 class="font-bold text-slate-900 mb-4 italic">
                      5. Recepción y Uso
                    </h3>
                    <p class="text-sm text-slate-600">
                      Recolección automática de IP, tipo de navegador y visitas para
                      mejorar la experiencia técnica y académica del cliente.
                    </p>
                  </section>
                </div>

                <!-- 7. DERECHOS -->
                <section id="derechos" class="mb-16 scroll-mt-32">
                  <h2
                    class="text-2xl font-bold text-slate-900 mb-8 uppercase tracking-tight"
                  >
                    7. Derechos de los Clientes
                  </h2>
                  <div class="grid grid-cols-1 gap-4">
                    <div
                      v-for="(derecho, i) in [
                        'Conocer, actualizar y rectificar datos.',
                        'Solicitar prueba de autorización.',
                        'Ser informado sobre el uso de sus datos.',
                        'Presentar quejas ante la SIC.',
                        'Revocar autorización o suprimir datos.',
                        'Acceder gratuitamente a sus datos personales.',
                      ]"
                      :key="i"
                      class="flex gap-4 p-4 hover:bg-slate-50 rounded-xl transition-colors"
                    >
                      <span class="font-black text-rose-600">0{{ i + 1 }}.</span>
                      <p class="text-slate-700 text-sm md:text-base">{{ derecho }}</p>
                    </div>
                  </div>
                </section>

                <!-- ATENCIÓN -->
                <section
                  id="atencion"
                  class="mb-16 scroll-mt-32 bg-slate-900 text-white p-8 rounded-3xl relative overflow-hidden"
                >
                  <div class="relative z-10">
                    <h2 class="text-2xl font-bold mb-6">9. ATENCIÓN AL CLIENTE</h2>
                    <p class="mb-8 text-slate-400">
                      Para consultas, reclamos o ejercicio de derechos (Habeas Data):
                    </p>
                    <div class="flex flex-col md:flex-row gap-8">
                      <div class="flex items-center gap-3">
                        <div
                          class="w-10 h-10 bg-white/10 rounded-full flex items-center justify-center font-bold"
                        >
                          @
                        </div>
                        <span class="font-mono text-sm"
                          >habeasdata@fycconsultores.com</span
                        >
                      </div>
                      <div class="flex items-center gap-3">
                        <div
                          class="w-10 h-10 bg-white/10 rounded-full flex items-center justify-center font-bold"
                        >
                          #
                        </div>
                        <span class="font-mono text-sm">Conmutador: 3211800</span>
                      </div>
                    </div>
                  </div>
                </section>

                <!-- MANUAL INTERNO (Muro de texto organizado) -->
                <section
                  id="manual-interno"
                  class="scroll-mt-32 bg-slate-50 p-8 md:p-12 rounded-[3rem] border border-slate-200"
                >
                  <div class="max-w-4xl mx-auto">
                    <h2
                      class="text-2xl md:text-3xl font-black text-slate-900 mb-10 border-b border-slate-200 pb-6 flex items-center gap-4"
                    >
                      <svg
                        class="w-8 h-8 text-rose-600"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"
                        />
                      </svg>
                      MANUAL INTERNO DE POLÍTICAS Y PROCEDIMIENTOS
                    </h2>

                    <div class="space-y-12 text-slate-600 leading-relaxed">
                      <!-- CAPÍTULO I -->
                      <div class="space-y-6">
                        <h3
                          class="font-black text-slate-900 uppercase tracking-wider flex items-center gap-2"
                        >
                          <span class="bg-slate-900 text-white text-xs px-2 py-1 rounded"
                            >CAPÍTULO I</span
                          >
                          Disposiciones Generales
                        </h3>

                        <div class="grid grid-cols-1 gap-6 text-sm">
                          <p>
                            <span class="font-bold text-slate-800">OBJETO:</span> Este
                            manual da cumplimiento al literal k) del artículo 17 de la Ley
                            1581 de 2012, regulando los deberes de los responsables del
                            tratamiento de datos personales y garantizando el derecho
                            fundamental de habeas data en F&C CONSULTORES S.A.S.
                          </p>
                          <p>
                            <span class="font-bold text-slate-800"
                              >LEGISLACIÓN APLICABLE:</span
                            >
                            Artículos 15 y 20 de la Constitución Política, Ley 1581 de
                            2012 y Decreto 1377 de 2013.
                          </p>
                          <p>
                            <span class="font-bold text-slate-800"
                              >ÁMBITO DE APLICACIÓN:</span
                            >
                            Aplicable al tratamiento de datos de carácter personal que
                            recoja y maneje la sociedad.
                          </p>
                        </div>

                        <div class="bg-white p-6 rounded-2xl border border-slate-200">
                          <h4
                            class="font-bold text-slate-800 mb-4 uppercase text-xs tracking-widest"
                          >
                            Definiciones Clave
                          </h4>
                          <div
                            class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4 text-xs"
                          >
                            <p>
                              <b>a) Autorización:</b> Consentimiento previo, expreso e
                              informado.
                            </p>
                            <p>
                              <b>b) Aviso de privacidad:</b> Documento físico o
                              electrónico para el Titular.
                            </p>
                            <p>
                              <b>c) Base de Datos:</b> Conjunto organizado de datos
                              personales.
                            </p>
                            <p>
                              <b>d) Dato personal:</b> Información vinculada a personas
                              naturales.
                            </p>
                            <p>
                              <b>e) Dato privado:</b> Información de naturaleza íntima o
                              reservada.
                            </p>
                            <p>
                              <b>f) Datos sensibles:</b> Afectan la intimidad o pueden
                              generar discriminación.
                            </p>
                            <p>
                              <b>g) Encargado:</b> Realiza el tratamiento por cuenta del
                              Responsable.
                            </p>
                            <p>
                              <b>h) Responsable:</b> Quien decide sobre la base de datos.
                            </p>
                            <p>
                              <b>i) Titular:</b> Persona natural objeto del tratamiento.
                            </p>
                            <p>
                              <b>j) Tratamiento:</b> Recolección, almacenamiento, uso o
                              supresión.
                            </p>
                          </div>
                        </div>

                        <div>
                          <h4 class="font-bold text-slate-800 mb-4 text-sm">
                            PRINCIPIOS RECTORES
                          </h4>
                          <ul
                            class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs italic"
                          >
                            <li class="flex gap-2">
                              <span>•</span> <b>Finalidad:</b> Obedece a un fin legítimo
                              informado.
                            </li>
                            <li class="flex gap-2">
                              <span>•</span> <b>Libertad:</b> Solo con consentimiento
                              previo o mandato legal.
                            </li>
                            <li class="flex gap-2">
                              <span>•</span> <b>Veracidad:</b> Información exacta,
                              actualizada y comprobable.
                            </li>
                            <li class="flex gap-2">
                              <span>•</span> <b>Transparencia:</b> Derecho del Titular a
                              obtener información.
                            </li>
                            <li class="flex gap-2">
                              <span>•</span> <b>Acceso Restringido:</b> No disponibilidad
                              en medios masivos sin control.
                            </li>
                            <li class="flex gap-2">
                              <span>•</span> <b>Seguridad:</b> Medidas técnicas para
                              evitar adulteración o pérdida.
                            </li>
                            <li class="flex gap-2">
                              <span>•</span> <b>Confidencialidad:</b> Reserva garantizada
                              incluso post-vínculo.
                            </li>
                          </ul>
                        </div>
                      </div>

                      <!-- CAPÍTULO II -->
                      <div class="space-y-6 pt-6 border-t border-slate-200">
                        <h3
                          class="font-black text-slate-900 uppercase tracking-wider flex items-center gap-2"
                        >
                          <span class="bg-slate-900 text-white text-xs px-2 py-1 rounded"
                            >CAPÍTULO II</span
                          >
                          Autorización y Aviso de Privacidad
                        </h3>
                        <div class="text-sm space-y-4">
                          <p>
                            La recolección y uso de datos requiere consentimiento libre y
                            previo. F&C CONSULTORES S.A.S. mantiene registros de cuándo y
                            cómo obtuvo dicha autorización, ya sea física o electrónica.
                          </p>
                          <div
                            class="bg-slate-100 p-5 rounded-xl border-2 border-rose-200"
                          >
                            <p class="font-bold text-xs uppercase text-slate-500 mb-2">
                              Contenido del Aviso de Privacidad:
                            </p>
                            <p class="text-xs">
                              Identidad y contacto del Responsable, tipo de tratamiento,
                              finalidad, derechos del titular y mecanismos para conocer la
                              política de cambios.
                            </p>
                          </div>
                        </div>
                      </div>

                      <!-- CAPÍTULO III -->
                      <div class="space-y-6 pt-6 border-t border-slate-200">
                        <h3
                          class="font-black text-slate-900 uppercase tracking-wider flex items-center gap-2"
                        >
                          <span class="bg-slate-900 text-white text-xs px-2 py-1 rounded"
                            >CAPÍTULO III</span
                          >
                          Derechos y Deberes
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 text-xs">
                          <div>
                            <h4
                              class="font-bold text-slate-800 mb-3 uppercase tracking-tighter"
                            >
                              Derechos del Titular:
                            </h4>
                            <ul class="space-y-2 list-decimal pl-4">
                              <li>Conocer, actualizar y rectificar datos.</li>
                              <li>Solicitar prueba de autorización.</li>
                              <li>Ser informado del uso dado a sus datos.</li>
                              <li>Presentar quejas ante la SIC.</li>
                              <li>Revocar autorización y/o solicitar supresión.</li>
                              <li>Acceso gratuito a sus datos.</li>
                            </ul>
                          </div>
                          <div>
                            <h4
                              class="font-bold text-slate-800 mb-3 uppercase tracking-tighter"
                            >
                              Deberes del Responsable:
                            </h4>
                            <p class="mb-2 italic">
                              F&C CONSULTORES S.A.S. se compromete a:
                            </p>
                            <ul class="space-y-1 list-disc pl-4">
                              <li>Garantizar el ejercicio del Habeas Data.</li>
                              <li>Conservar información bajo seguridad técnica.</li>
                              <li>Tramitar consultas y reclamos en términos de ley.</li>
                              <li>Informar a la SIC sobre violaciones de seguridad.</li>
                              <li>Cumplir instrucciones de la autoridad competente.</li>
                            </ul>
                          </div>
                        </div>
                      </div>

                      <!-- CAPÍTULO IV -->
                      <div class="space-y-6 pt-6 border-t border-slate-200">
                        <h3
                          class="font-black text-slate-900 uppercase tracking-wider flex items-center gap-2"
                        >
                          <span class="bg-slate-900 text-white text-xs px-2 py-1 rounded"
                            >CAPÍTULO IV</span
                          >
                          Procedimientos de Acceso, Consulta y Reclamación
                        </h3>

                        <div
                          class="bg-white p-6 rounded-2xl border border-rose-100 shadow-sm space-y-4"
                        >
                          <div class="flex items-center gap-3 text-rose-600 mb-2">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                              <path
                                fill-rule="evenodd"
                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z"
                                clip-rule="evenodd"
                              ></path>
                            </svg>
                            <span class="font-bold text-sm uppercase"
                              >Términos de Respuesta Legal</span
                            >
                          </div>
                          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="p-4 bg-slate-50 rounded-xl">
                              <p class="font-bold text-slate-800 text-sm mb-1 italic">
                                Consultas:
                              </p>
                              <p class="text-xs">
                                Máximo <b>10 días hábiles</b> desde el recibo. Prórroga de
                                hasta 8 días informando al interesado.
                              </p>
                            </div>
                            <div class="p-4 bg-slate-50 rounded-xl">
                              <p class="font-bold text-slate-800 text-sm mb-1 italic">
                                Reclamos:
                              </p>
                              <p class="text-xs">
                                Máximo <b>15 días hábiles</b> (según Ley 1581, ajustado a
                                10 días internos). Prórroga de 8 días hábiles.
                              </p>
                            </div>
                          </div>
                          <p class="text-[11px] text-slate-400 italic">
                            Nota: Si el reclamo está incompleto, se requerirá al
                            interesado dentro de los 5 días siguientes para subsanar.
                            Pasados 2 meses sin respuesta, se entenderá desistido.
                          </p>
                        </div>

                        <div class="text-xs space-y-4 text-justify">
                          <p>
                            <span class="font-bold">SUPRESIÓN DE DATOS:</span> El titular
                            puede solicitar la eliminación cuando considere que el
                            tratamiento no cumple los principios legales.
                            <b>No procederá</b> cuando el titular tenga un deber legal o
                            contractual de permanecer en la base de datos o existan
                            actuaciones judiciales/fiscales pendientes.
                          </p>
                          <p>
                            <span class="font-bold">REVOCATORIA:</span> Puede ser total o
                            parcial. Debe indicarse claramente con qué tratamiento no se
                            está conforme.
                          </p>
                        </div>
                      </div>

                      <!-- CAPÍTULO V -->
                      <div class="space-y-6 pt-6 border-t border-slate-200">
                        <h3
                          class="font-black text-slate-900 uppercase tracking-wider flex items-center gap-2"
                        >
                          <span class="bg-slate-900 text-white text-xs px-2 py-1 rounded"
                            >CAPÍTULO V</span
                          >
                          Seguridad de la Información
                        </h3>
                        <div
                          class="text-xs bg-slate-800 text-slate-300 p-6 rounded-2xl space-y-4"
                        >
                          <p class="text-white font-bold">
                            Implementación de medidas (Artículo 21):
                          </p>
                          <ul class="grid grid-cols-1 gap-2 opacity-90">
                            <li>
                              • Protocolos obligatorios para personal con acceso a
                              sistemas.
                            </li>
                            <li>
                              • Procedimientos de notificación y respuesta ante
                              incidencias.
                            </li>
                            <li>
                              • Copias de respaldo y recuperación de datos periódicas.
                            </li>
                            <li>
                              • Medidas para transporte y desecho seguro de soportes
                              físicos.
                            </li>
                            <li>• Auditorías periódicas de cumplimiento normativo.</li>
                          </ul>
                        </div>
                      </div>

                      <!-- CAPÍTULO VI -->
                      <div class="space-y-6 pt-6 border-t border-slate-200">
                        <div
                          class="flex flex-col md:flex-row justify-between items-start gap-6"
                        >
                          <div>
                            <h3
                              class="font-black text-slate-900 uppercase tracking-wider mb-4"
                            >
                              CAPÍTULO VI - Disposiciones Finales
                            </h3>
                            <p class="text-sm font-bold text-slate-800">Designación:</p>
                            <p class="text-xs mb-4">
                              Coordinador del Call Center – Servicio al cliente (Función
                              de Protección de Datos Personales).
                            </p>
                            <p class="text-sm font-bold text-slate-800">Vigencia:</p>
                            <p class="text-xs">
                              El presente manual rige a partir del
                              <b>13 de agosto de 2013</b>.
                            </p>
                          </div>
                          <div
                            class="w-full md:w-auto bg-rose-50 p-6 rounded-2xl border border-rose-100 flex flex-col items-center justify-center"
                          >
                            <p
                              class="text-[10px] font-black text-rose-600 uppercase mb-2"
                            >
                              Habeas Data Contacto
                            </p>
                            <p class="text-xs font-mono font-bold">
                              habeasdata@fycconsultores.com
                            </p>
                          </div>
                        </div>
                      </div>

                      <p
                        class="text-[10px] text-slate-400 mt-12 text-center uppercase tracking-[0.2em] font-medium"
                      >
                        F&C CONSULTORES S.A.S. - Transparencia y Responsabilidad
                      </p>
                    </div>
                  </div>
                </section>
              </div>
            </RevealSection>
          </div>
        </div>
      </main>

      <SiteFooter />
    </div>
  </GuestLayout>
</template>

<style scoped>
html {
  scroll-behavior: smooth;
}

.prose h2 {
  letter-spacing: -0.025em;
}

.prose p {
  margin-bottom: 1.5em;
  line-height: 1.8;
}

section p:hover {
  color: #0f172a;
  transition: color 0.3s ease;
}
</style>
