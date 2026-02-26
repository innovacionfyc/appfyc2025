<script setup>
import { computed, watch, ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import BaseModalSteps from '@/Components/Shared/Modales/BaseModalSteps.vue';
import { formatCOP } from '@/Utils/formateoMoneda';
import { getInicialesProducto } from "@/Utils/inicialesNombres";
import { getFotoProductoUrlCompleta } from "@/Utils/ImagenUsuarios";
import Selects from '@/Components/Shared/inputs/Selects.vue';
import InputTexto from '@/Components/Shared/inputs/InputTexto.vue';

const props = defineProps({
    isOpen: { type: Boolean, required: true },
    order: { type: Object, default: null },
    productosDisponibles: { type: Array, required: true },
    aplicacion: { type: String, required: true },
    rol: { type: String, required: true },
});

const emit = defineEmits(['close']);

const form = useForm({
    cart: [],
    subtotal: 0,
    total: 0,
    notas_generales: '',
});

// Control del item activo para edición
const activeProductIndex = ref(null);

watch(() => props.order, (newOrder) => {
    if (newOrder) {
        // 1. CONSOLIDAR ÍTEMS DE LA ORDEN ORIGINAL
        const consolidatedItems = consolidateOrderItems(newOrder.items);

        // 2. Mapear los ítems consolidados a la estructura del carrito
        const initialCart = consolidatedItems.map(item => ({
            // Usamos item.id para ítems existentes (si vienen del backend)
            // Para nuevos ítems, el ID no será necesario para la actualización, 
            // pero lo mantenemos para consistencia en el FE.
            id: item.id || `temp-${Date.now()}-${item.productId}`,
            productId: item.producto_id,
            producto_nombre: item.producto_nombre,
            producto_foto: item.producto_foto,
            quantity: item.cantidad, // La cantidad ya es la suma
            price: item.precio_unitario,
            comment: item.notas_item || '',
        }));

        form.defaults({
            cart: initialCart,
            subtotal: newOrder.subtotal,
            total: newOrder.total,
            notas_generales: newOrder.notas_generales || '',
        });

        // Resetear el formulario a los valores por defecto
        form.reset();
        activeProductIndex.value = null;
    }
}, { immediate: true, deep: true });

// Nueva función de ayuda para consolidar los productos
function consolidateOrderItems(items) {
    const itemMap = new Map();

    items.forEach(item => {
        // La clave de consolidación debe ser una combinación de producto_id y notas_item
        // Si las notas son importantes, se incluyen. Si no, solo se usa producto_id.
        // Asumiremos que notas_item también diferencia un producto.
        const key = `${item.producto_id}-${item.notas_item || ''}`;

        if (itemMap.has(key)) {
            // Si ya existe, suma la cantidad
            const existingItem = itemMap.get(key);
            existingItem.cantidad += item.cantidad;

            // Conservar el ID del primer ítem si existe, o usar un ID temporal para nuevos
            if (!existingItem.id && item.id) {
                existingItem.id = item.id;
            }
        } else {
            // Si no existe, agregar el ítem
            itemMap.set(key, { ...item });
        }
    });

    return Array.from(itemMap.values());
}

const computedSubtotal = computed(() => {
    return form.cart.reduce((acc, item) => {
        const qty = parseInt(item.quantity) || 0;
        const price = parseFloat(item.price) || 0;
        return acc + (qty * price);
    }, 0);
});

// Calcula la propina y el total basado en el nuevo subtotal
watch(computedSubtotal, (newSubtotal) => {
    form.subtotal = newSubtotal;

    // Obtener el porcentaje de propina de la orden original (si existe)
    const porcentajePropina = props.order?.porcentaje_propina || 0;

    // Calcular el monto de propina con el nuevo subtotal
    const propinaCalculada = newSubtotal * (porcentajePropina / 100);

    // Calcular el total final
    form.total = newSubtotal + propinaCalculada;

    // Nota: Si hay otros descuentos/impuestos en 'order', también deberían replicarse aquí.
}, { deep: true }); // Usar deep: true asegura que los cambios internos de 'cart' disparen el watch

// --- ACCIONES DEL CARRITO ---
function removeItem(index) {
    form.cart.splice(index, 1);
    if (activeProductIndex.value === index) {
        activeProductIndex.value = null;
    } else if (activeProductIndex.value > index) {
        activeProductIndex.value--;
    }
}

function addItem() {
    form.cart.push({
        id: `new-${Date.now()}`,
        productId: null,
        producto_nombre: 'Nuevo Producto',
        producto_foto: null,
        quantity: 1,
        price: 0,
        comment: '',
    });
    activeProductIndex.value = form.cart.length - 1;
}

function onProductSelect(item, index) {
    const selectedProduct = props.productosDisponibles.find(p => p.id === item.productId);
    if (selectedProduct) {
        form.cart[index].producto_nombre = selectedProduct.text.split(' - ')[1] || 'Producto';
        form.cart[index].producto_foto = selectedProduct.imagen_url;
        form.cart[index].price = selectedProduct.precio;
    }
}

function decrementQuantity(item) {
    if (item.quantity > 1) item.quantity--;
}
function incrementQuantity(item) {
    item.quantity++;
}

// --- ✅ SUBMIT CORREGIDO ---
function handleSubmit() {
    if (!props.order) return;

    // Usamos transform para adaptar 'cart' (Frontend) a 'items' (Backend)
    form.transform((data) => {
        // NO enviamos el ID del ítem de la orden (item.id) si el backend
        // espera solo el ID del PRODUCTO (item.productId) para crear/actualizar.
        return {
            items: data.cart.map(item => ({
                id: item.productId,        // El controlador espera 'id' (del Producto)
                cantidad: item.quantity,   // El controlador espera 'cantidad'
                precio_unitario: item.price, // El controlador espera 'precio_unitario'
                notas_item: item.comment   // El controlador espera 'notas_item'
            })),
            notas_generales: data.notas_generales,
            subtotal: data.subtotal,
            total: data.total
        }
    }).put(route('repos.ordenes.update', {
        aplicacion: props.aplicacion,
        rol: props.rol,
        orden: props.order.id
    }), {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
            form.reset();
        },
        onError: (errors) => {
            console.error("Errores de validación:", errors);
            // Esto te ayudará a ver si hay errores ocultos en la consola
        }
    });
}

import Swal from 'sweetalert2'; // Importar arriba

function confirmarCancelacion() {
    Swal.fire({
        title: '¿Cancelar Orden?',
        text: "Esta acción devolverá el stock y no se puede deshacer.",
        icon: 'warning',
        input: 'text', // Input de texto
        inputPlaceholder: 'Escribe el motivo (ej: Cliente se retiró)',
        showCancelButton: true,
        confirmButtonColor: '#ef4444', // Rojo
        cancelButtonColor: '#374151', // Gris
        confirmButtonText: 'Sí, cancelar orden',
        cancelButtonText: 'Volver',
        inputValidator: (value) => {
            if (!value) {
                return '¡Necesitas escribir un motivo!';
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // El usuario confirmó y escribió un motivo
            router.post(route('repos.ordenes.cancelar', {
                aplicacion: props.aplicacion,
                rol: props.rol,
                orden: props.order.id
            }), {
                motivo: result.value // Enviamos el valor del input
            }, {
                preserveScroll: true,
                onSuccess: () => {
                    emit('close');

                },
                onError: (errors) => {
                    console.error(errors);

                }
            });
        }
    });
}

const getStatusIcon = (status) => {
    switch (status) {
        case 'Pagado': return 'check_circle';
        case 'Procesando': return 'sync';
        case 'Anulado': return 'cancel';
        case 'Reembolsado': return 'cancel';
        case 'Pendiente de Pago': return 'pending';
        default: return 'info';
    }
};

const getStatusStyles = (status) => {
    switch (status) {
        case 'Pagado': return 'bg-emerald-100 text-emerald-700 ring-emerald-600/20';
        case 'Procesando': return 'bg-amber-100 text-amber-700 ring-amber-600/20';
        case 'Anulado': return 'bg-red-100 text-red-700 ring-red-600/20';
        case 'Reembolsado': return 'bg-red-100 text-red-700 ring-red-600/20';
        case 'Pendiente de Pago': return 'bg-blue-100 text-blue-700 ring-blue-600/20';
        default: return 'bg-gray-100 text-gray-700 ring-gray-600/20';
    }
};
</script>
<template>
    <BaseModalSteps :isOpen="isOpen" :currentStep="1" :totalSteps="1" finalButtonText="Actualizar orden"
        @close="emit('close')" @submit="handleSubmit" title="Detalles de la orden"
        description="Aquí podrás observar todos los detalles de la orden gestionada por el mesero.">
        <div v-if="order" class="flex flex-col h-full">

            <div class="flex items-center justify-between gap-3">
                <div class="">
                    <h2 class="text-3xl font-black text-gray-900 dark:text-white tracking-tight">
                        ORDEN #{{ order.consecutivo_completo }}
                    </h2>
                    <p class="text-sm text-gray-500 mt-1 flex items-center gap-2">
                        <span class="material-symbols-rounded text-base">event</span> {{ order.fecha }}
                    </p>
                </div>

                <button v-if="order.estado === 'Pendiente de Pago'" @click="confirmarCancelacion" type="button"
                    class="px-3 py-1.5 text-sm sm:text-base font-bold rounded-full border bg-red-100 text-red-800 border-red-200 hover:bg-red-200 transition w-full sm:w-auto">
                    Cancelar Orden
                </button>





            </div>
            <div v-if="order" class="text-gray-700 p-4 sm:p-6 bg-white rounded-lg shadow-inner">

                <section class="py-4 border-b border-gray-200 mb-6">
                    <h3 class="font-bold text-lg mb-4 text-gray-800 flex items-center gap-2">
                        <span class="material-symbols-rounded text-xl text-primary">shopping_cart</span>
                        Productos listados por: {{ order.empleado }}
                    </h3>

                    <div class="max-h-60 sm:max-h-72 overflow-y-auto pr-3">
                        <TransitionGroup name="list-fade" tag="div" class="space-y-3">
                            <div v-for="(item, index) in form.cart" :key="item.id || index">

                                <div v-if="index === activeProductIndex"
                                    class="p-4 border-2 border-primary dark:border-orange-400 rounded-lg space-y-4 relative bg-gray-50 dark:bg-gray-800/50 shadow-lg">

                                    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4">
                                        <Selects v-model="item.productId" :options="props.productosDisponibles"
                                            :error="form.errors[`items.${index}.id`]" label="Producto:"
                                            placeholder="Selecciona un producto" :id="`producto_id_editar_${index}`"
                                            @update:modelValue="() => onProductSelect(item, index)" class="w-full" />

                                        <div class="flex-shrink-0 w-full sm:w-auto">
                                            <label
                                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cantidad:</label>
                                            <div class="flex items-center flex-shrink-0">
                                                <button @click.prevent="decrementQuantity(item)"
                                                    :disabled="item.quantity <= 1"
                                                    class="w-8 h-8 rounded-full bg-gray-200 text-secondary hover:bg-gray-300 flex items-center justify-center transition">
                                                    <span class="material-symbols-rounded text-lg">remove</span>
                                                </button>
                                                <span class="w-10 text-center font-bold text-secondary text-lg">{{
                                                    item.quantity }}</span>
                                                <button @click.prevent="incrementQuantity(item)"
                                                    class="w-8 h-8 rounded-full bg-primary text-white hover:bg-orange-600 flex items-center justify-center transition">
                                                    <span class="material-symbols-rounded text-lg">add</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <InputTexto v-model="item.comment" label="Notas" icon="chat_bubble"
                                        placeholder="Ej. Sin cebolla" :error="form.errors[`items.${index}.notas_item`]"
                                        class="w-full" />

                                    <button @click="activeProductIndex = null" type="button"
                                        class="absolute -top-3 right-2 text-gray-400 hover:text-gray-600 p-1 bg-white rounded-full border shadow-sm">
                                        <span class="material-symbols-rounded text-lg">close</span>
                                    </button>
                                </div>

                                <div v-else @click="activeProductIndex = index"
                                    class="p-3 border rounded-lg flex flex-col sm:flex-row justify-between items-start sm:items-center cursor-pointer hover:bg-gray-100 transition">

                                    <div class="flex items-center gap-3 min-w-0 w-full">
                                        <div class="w-10 h-10 rounded-lg overflow-hidden bg-gray-200 flex-shrink-0">
                                            <img v-if="item.producto_foto"
                                                :src="getFotoProductoUrlCompleta(item.producto_foto)"
                                                class="w-full h-full object-cover">
                                            <div v-else
                                                class="w-full h-full flex items-center justify-center text-gray-500 font-bold">
                                                {{ getInicialesProducto(item.producto_nombre) }}</div>
                                        </div>
                                        <div class="flex-grow min-w-0">
                                            <p class="font-semibold text-secondary truncate">{{ item.producto_nombre }}
                                            </p>
                                            <p v-if="item.comment" class="text-xs text-blue-600 italic truncate">{{
                                                item.comment }}</p>
                                        </div>
                                    </div>

                                    <div
                                        class="flex items-center gap-4 w-full sm:w-auto justify-between sm:justify-end mt-2 sm:mt-0">
                                        <div class="text-right">
                                            <p class="font-bold text-primary">{{ formatCOP(item.price * (item.quantity
                                                ||
                                                0)) }}</p>
                                            <p class="text-xs text-gray-500">{{ item.quantity }} x {{
                                                formatCOP(item.price)
                                                }}</p>
                                        </div>
                                        <button @click.stop="removeItem(index)" type="button"
                                            class="text-gray-400 hover:text-red-500">
                                            <span class="material-symbols-rounded">delete</span>
                                        </button>
                                    </div>
                                </div>

                            </div>
                        </TransitionGroup>
                    </div>

                    <div class="pt-4">
                        <button @click.prevent="addItem" type="button"
                            class="flex items-center gap-2 text-primary font-semibold hover:opacity-80 transition">
                            <span class="material-symbols-rounded">add_circle</span> Agregar producto
                        </button>
                    </div>
                </section>

                <div
                    class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6 pt-4 border-t border-gray-100 dark:border-gray-800">

                    <div class="space-y-3 text-sm">
                        <h4 class="font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-rounded text-primary">receipt_long</span> Datos de Facturación
                        </h4>
                        <div
                            class="bg-gray-50 dark:bg-gray-800/50 p-3 rounded-xl space-y-2 border border-gray-100 dark:border-gray-700">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Estado:</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{
                                    order.factura_info.estado_factura }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Método:</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{
                                    order.factura_info.detalles_pago || 'Pendiente' }}</span>
                            </div>
                            <div class="flex justify-between" v-if="order.factura_info.fecha_pago">
                                <span class="text-gray-500">Pagado el:</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{
                                    order.factura_info.fecha_pago
                                    }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <div class="flex justify-between text-gray-600 dark:text-gray-400 text-sm">
                            <span>Subtotal</span>
                            <span class="font-mono">{{ formatCOP(form.subtotal) }}</span>
                        </div>
                        <div class="flex justify-between text-gray-600 dark:text-gray-400 text-sm">
                            <span>Propina ({{ order.porcentaje_propina?.toFixed(0) || 0 }}%)</span>
                            <span class="font-mono">{{ formatCOP(form.total - form.subtotal) }}</span>
                        </div>

                        <div class="border-t-2 border-dashed border-gray-200 dark:border-gray-700 my-2"></div>

                        <div class="flex justify-between items-end">
                            <span class="font-bold text-gray-900 dark:text-white text-lg">Total</span>
                            <span class="font-mono font-black text-3xl text-primary bg-primary/5 px-2 rounded-lg">
                                {{ formatCOP(form.total) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>


    </BaseModalSteps>
</template>

<style scoped>
.max-h-60::-webkit-scrollbar,
.max-h-72::-webkit-scrollbar {
    width: 8px;
}

.max-h-60::-webkit-scrollbar-track,
.max-h-72::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.max-h-60::-webkit-scrollbar-thumb,
.max-h-72::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 10px;
}

.max-h-60::-webkit-scrollbar-thumb:hover,
.max-h-72::-webkit-scrollbar-thumb:hover {
    background: #9ca3af;
}

.list-fade-enter-active,
.list-fade-leave-active {
    transition: all 0.5s ease;
}

.list-fade-enter-from,
.list-fade-leave-to {
    opacity: 0;
    transform: translateY(20px);
}
</style>

<style scoped>
.max-h-72::-webkit-scrollbar {
    width: 8px;
}

.max-h-72::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.max-h-72::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 10px;
}

.max-h-72::-webkit-scrollbar-thumb:hover {
    background: #9ca3af;
}

.list-fade-enter-active,
.list-fade-leave-active {
    transition: all 0.5s ease;
}

.list-fade-enter-from,
.list-fade-leave-to {
    opacity: 0;
    transform: translateY(20px);
}
</style>