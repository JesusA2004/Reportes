<script setup lang="ts">
    import { ref } from 'vue'
    import { Form, Head } from '@inertiajs/vue3'
    import InputError from '@/components/InputError.vue'
    import TextLink from '@/components/TextLink.vue'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Spinner } from '@/components/ui/spinner'
    import { store } from '@/routes/login'
    import { request } from '@/routes/password'
    import bgDesktop from '@/img/bgLoginDesktop.jpg'
    import bgMobile from '@/img/bgLoginMovil.jpg'

    defineProps<{
        status?: string
        canResetPassword: boolean
        canRegister: boolean
    }>()

    const year = new Date().getFullYear()
    const showPassword = ref(false)
</script>

<template>
    <Head title="Iniciar sesión" />

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

                <h1 class="fv-title">Bienvenido a Reportes</h1>
                <p class="fv-subtitle">Accede a tu cuenta para entrar al panel.</p>

                <div v-if="status" class="fv-status">
                {{ status }}
                </div>
            </div>

            <Form
                v-bind="store.form()"
                :reset-on-success="['password']"
                v-slot="{ errors, processing }"
                class="fv-form"
            >
                <div v-if="processing" class="fv-wait" role="status" aria-live="polite">
                <Spinner />
                <span>Validando usuario…</span>
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
                    required
                    autofocus
                    autocomplete="email"
                    placeholder="correo@ejemplo.com"
                    class="fv-input"
                />
                <InputError :message="errors.email" />
                </div>

                <div class="fv-field">
                <div class="fv-row">
                    <Label for="password" class="fv-label">Contraseña</Label>
                </div>

                <div class="fv-pass">
                    <Input
                    id="password"
                    :type="showPassword ? 'text' : 'password'"
                    name="password"
                    required
                    autocomplete="current-password"
                    placeholder="••••••••"
                    class="fv-input fv-input-pass"
                    />

                    <button
                    type="button"
                    class="fv-eye"
                    :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                    :title="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                    @click="showPassword = !showPassword"
                    >
                    <svg
                        v-if="!showPassword"
                        viewBox="0 0 24 24"
                        class="h-5 w-5"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>

                    <svg
                        v-else
                        viewBox="0 0 24 24"
                        class="h-5 w-5"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M3 3l18 18"/>
                        <path d="M10.6 10.6a2.5 2.5 0 0 0 3.3 3.3"/>
                        <path d="M9.9 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a18.3 18.3 0 0 1-4.3 5.2"/>
                        <path d="M6.2 6.2C3.5 8.2 2 12 2 12s3.5 7 10 7c1 0 1.9-.1 2.8-.4"/>
                    </svg>
                    </button>
                </div>

                <InputError :message="errors.password" />
                </div>

                <div class="fv-row fv-end">
                <TextLink v-if="canResetPassword" :href="request()" class="fv-link">
                    ¿Olvidó su contraseña?
                </TextLink>
                </div>

                <Button
                type="submit"
                class="fv-btn"
                :disabled="processing"
                data-test="login-button"
                >
                <span class="fv-btn-shine" aria-hidden="true" />
                <span class="fv-btn-content">
                    <Spinner v-if="processing" />
                    <span>{{ processing ? 'Validando…' : 'Iniciar sesión' }}</span>
                </span>
                <span v-if="processing" class="fv-btn-bar" aria-hidden="true"></span>
                </Button>
            </Form>
            </div>

            <div class="fv-bottom">
            <div class="fv-badge">
                <span>© {{ year }} Reportes</span>
            </div>
            </div>
        </div>
        </div>
    </main>
</template>

<style scoped src="@/../css/login.css"></style>
