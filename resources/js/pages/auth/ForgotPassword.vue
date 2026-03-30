<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3'
import InputError from '@/components/InputError.vue'
import TextLink from '@/components/TextLink.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Spinner } from '@/components/ui/spinner'
import { login } from '@/routes'
import { email } from '@/routes/password'

import bgDesktop from '@/img/bgLoginDesktop.jpg'
import bgMobile from '@/img/bgLoginMovil.jpg'

defineOptions({
    layout: {
        title: 'Recuperar contraseña',
        description: 'Ingresa tu correo para recibir un enlace de restablecimiento',
    },
})

defineProps<{
    status?: string
}>()

const year = new Date().getFullYear()
</script>

<template>
    <Head title="Recuperar contraseña" />

    <main class="fv-screen">
        <div class="fv-bg" aria-hidden="true">
            <picture class="fv-picture">
                <source :srcset="bgDesktop" media="(min-width: 768px)" />
                <img :src="bgMobile" alt="" class="fv-bg-img" draggable="false" />
            </picture>
        </div>

        <div class="fv-center">
            <div class="fv-stack">
                <div class="fv-card">
                    <div class="fv-brand">
                        <div class="fv-logo">
                            <img src="/favicon.ico" alt="Reportes" class="fv-logo-img" draggable="false" />
                        </div>

                        <h1 class="fv-title">Recuperar contraseña</h1>
                        <p class="fv-subtitle">
                            Ingresa tu correo y te enviaremos un enlace para restablecer tu acceso.
                        </p>

                        <div v-if="status" class="fv-status">
                            {{ status }}
                        </div>
                    </div>

                    <Form v-bind="email.form()" v-slot="{ errors, processing }" class="fv-form">
                        <div v-if="processing" class="fv-wait" role="status" aria-live="polite">
                            <Spinner />
                            <span>Enviando enlace…</span>
                            <span class="fv-dots" aria-hidden="true">
                                <span></span><span></span><span></span>
                            </span>
                        </div>

                        <div class="fv-field">
                            <Label for="email" class="fv-label">Correo electrónico</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autocomplete="off"
                                autofocus
                                placeholder="correo@ejemplo.com"
                                class="fv-input"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <Button
                            class="fv-btn"
                            :disabled="processing"
                            data-test="email-password-reset-link-button"
                        >
                            <span class="fv-btn-shine" aria-hidden="true" />
                            <span class="fv-btn-content">
                                <Spinner v-if="processing" />
                                <span>{{ processing ? 'Enviando enlace…' : 'Enviar enlace de recuperación' }}</span>
                            </span>
                            <span v-if="processing" class="fv-btn-bar" aria-hidden="true"></span>
                        </Button>
                    </Form>
                </div>

                <div class="fv-bottom">
                    <div class="fv-badge">
                        <span>¿Recordaste tu contraseña?
                        <TextLink :href="login()" class="fv-link">Volver a iniciar sesión</TextLink></span>
                    </div>

                    <div class="fv-badge">© {{ year }} Reportes</div>
                </div>
            </div>
        </div>
    </main>
</template>

<style scoped src="@/../css/login.css"></style>
