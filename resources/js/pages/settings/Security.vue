<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ShieldCheck } from 'lucide-vue-next';
import { onUnmounted, ref } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { edit } from '@/routes/security';
import { disable, enable } from '@/routes/two-factor';

type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    requiresConfirmation: false,
    twoFactorEnabled: false,
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Seguridad',
                href: edit(),
            },
        ],
    },
});

const { hasSetupData, clearTwoFactorAuthData } = useTwoFactorAuth();
const showSetupModal = ref<boolean>(false);

onUnmounted(() => clearTwoFactorAuthData());
</script>

<template>
    <Head title="Seguridad" />

    <section class="space-y-8 rounded-3xl border border-sidebar-border/70 bg-background p-6 shadow-sm sm:p-8">
        <h1 class="sr-only">Configuración de seguridad</h1>

        <div class="space-y-6">
            <Heading
                variant="small"
                title="Cambiar contraseña"
                description="Usa una contraseña segura y difícil de adivinar para proteger tu cuenta."
            />

            <Form
                v-bind="SecurityController.update.form()"
                :options="{ preserveScroll: true }"
                reset-on-success
                :reset-on-error="[
                    'password',
                    'password_confirmation',
                    'current_password',
                ]"
                class="space-y-6"
                v-slot="{ errors, processing, recentlySuccessful }"
            >
                <div class="grid gap-2">
                    <Label for="current_password">Contraseña actual</Label>
                    <PasswordInput
                        id="current_password"
                        name="current_password"
                        class="w-full"
                        autocomplete="current-password"
                        placeholder="Escribe tu contraseña actual"
                    />
                    <InputError :message="errors.current_password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Nueva contraseña</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        class="w-full"
                        autocomplete="new-password"
                        placeholder="Escribe tu nueva contraseña"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirmar contraseña</Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        class="w-full"
                        autocomplete="new-password"
                        placeholder="Confirma tu nueva contraseña"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <div class="flex items-center gap-4">
                    <Button
                        :disabled="processing"
                        data-test="update-password-button"
                    >
                        Guardar contraseña
                    </Button>

                    <Transition
                        enter-active-class="transition ease-in-out duration-300"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out duration-300"
                        leave-to-class="opacity-0"
                    >
                        <p
                            v-show="recentlySuccessful"
                            class="text-sm text-muted-foreground"
                        >
                            Contraseña actualizada.
                        </p>
                    </Transition>
                </div>
            </Form>
        </div>

        <div
            v-if="canManageTwoFactor"
            class="space-y-6 border-t border-sidebar-border/70 pt-8"
        >
            <Heading
                variant="small"
                title="Autenticación en dos pasos"
                description="Administra una capa adicional de seguridad para el acceso a tu cuenta."
            />

            <template v-if="!twoFactorEnabled">
                <p class="text-sm text-muted-foreground">
                    Al activar la autenticación en dos pasos, se te pedirá un código seguro al iniciar sesión. Este código se obtiene desde una aplicación compatible con TOTP en tu teléfono.
                </p>

                <div>
                    <Button v-if="hasSetupData" @click="showSetupModal = true">
                        <ShieldCheck class="mr-2 h-4 w-4" />
                        Continuar configuración
                    </Button>

                    <Form
                        v-else
                        v-bind="enable.form()"
                        @success="showSetupModal = true"
                        #default="{ processing }"
                    >
                        <Button type="submit" :disabled="processing">
                            Activar autenticación en dos pasos
                        </Button>
                    </Form>
                </div>
            </template>

            <template v-else>
                <p class="text-sm text-muted-foreground">
                    Actualmente tu cuenta solicita un código de verificación al iniciar sesión, generado desde tu aplicación compatible con TOTP.
                </p>

                <Form v-bind="disable.form()" #default="{ processing }">
                    <Button
                        variant="destructive"
                        type="submit"
                        :disabled="processing"
                    >
                        Desactivar autenticación en dos pasos
                    </Button>
                </Form>

                <TwoFactorRecoveryCodes />
            </template>

            <TwoFactorSetupModal
                v-model:isOpen="showSetupModal"
                :requiresConfirmation="requiresConfirmation"
                :twoFactorEnabled="twoFactorEnabled"
            />
        </div>
    </section>
</template>
