<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Swal from 'sweetalert2';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Perfil',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const form = useForm({
    name: user.value.name ?? '',
});

const submit = () => {
    form.patch(edit().url, {
        preserveScroll: true,
        onSuccess: () => {
            Swal.fire({
                icon: 'success',
                title: 'Cambios guardados',
                text: 'La información del perfil se actualizó correctamente.',
                confirmButtonText: 'Aceptar',
            });
        },
        onError: (errors) => {
            const firstError =
                errors.name ||
                errors.email ||
                'Ocurrió un error al guardar los cambios. Verifica la información e inténtalo de nuevo.';

            Swal.fire({
                icon: 'error',
                title: 'No se pudo guardar',
                text: firstError,
                confirmButtonText: 'Entendido',
            });
        },
    });
};
</script>

<template>
    <Head title="Perfil" />

    <section class="space-y-6 rounded-3xl border border-sidebar-border/70 bg-background p-6 shadow-sm sm:p-8">
        <h1 class="sr-only">Configuración de perfil</h1>

        <Heading
            variant="small"
            title="Información de perfil"
            description="Actualiza tu nombre y consulta el correo asociado a tu cuenta."
        />

        <form class="space-y-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="name">Nombre</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    name="name"
                    required
                    autocomplete="name"
                    placeholder="Nombre completo"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Correo electrónico</Label>
                <Input
                    id="email"
                    type="email"
                    :model-value="user.email"
                    readonly
                    disabled
                    class="cursor-not-allowed opacity-80"
                />
                <p class="text-sm text-muted-foreground">
                    Este correo es solo informativo y no se puede modificar.
                </p>
            </div>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing" data-test="update-profile-button">
                    {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
                </Button>
            </div>
        </form>
    </section>
</template>
