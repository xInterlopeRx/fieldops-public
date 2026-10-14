<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    canLogin?: boolean;
    canBootstrap?: boolean;
}>();
</script>

<template>
    <Head title="FieldOps | FieldOps" />

    <main class="fieldops-landing">
        <div class="fieldops-landing__wrap">
            <header class="fieldops-brand-header">
                <Link href="/" class="fieldops-brand-lockup" aria-label="FieldOps Industrial Networks home">
                    <span class="fieldops-brand-name">byte<span>Harder</span></span>
                    <span class="fieldops-brand-subtitle">Industrial Networks</span>
                </Link>
                <span class="fieldops-brand-tag">Internal field operations</span>
            </header>

            <section class="fieldops-landing__hero" aria-labelledby="landing-title">
                <div class="fieldops-landing__copy">
                    <p class="fieldops-landing__eyebrow">FieldOps / FieldOps</p>
                    <h1 id="landing-title">Technology that moves your work forward.</h1>
                    <p class="fieldops-landing__intro">
                        Lorem ipsum dolor sit amet, consectetur adipiscing elit.
                        Nunc vitae justo non nulla facilisis iaculis. Curabitur at
                        dui sed erat consequat posuere.
                    </p>
                    <Link
                        v-if="$page.props.auth.user || canLogin"
                        class="fieldops-primary-link"
                        :href="$page.props.auth.user ? route('calendar') : route('login')"
                    >
                        {{ $page.props.auth.user ? 'Open FieldOps' : 'Staff sign in' }}
                    </Link>
                    <Link v-if="!$page.props.auth.user && canBootstrap" class="fieldops-primary-link" :href="route('installation.bootstrap.create')">
                        Create the first Admin
                    </Link>
                </div>

                <aside class="fieldops-landing__workflow" aria-labelledby="workflow-title">
                    <p class="fieldops-landing__eyebrow">Field operations</p>
                    <h2 id="workflow-title">A clear view of the workday.</h2>
                    <ul>
                        <li><span>01</span> Monthly schedule</li>
                        <li><span>02</span> Customer and site history</li>
                        <li><span>03</span> Jobs, visits, and notes</li>
                    </ul>
                </aside>
            </section>

            <footer class="fieldops-landing__footer">
                <span>FieldOps Industrial Networks</span>
                <span>Midland, Texas</span>
            </footer>
        </div>
    </main>
</template>

<style scoped>
.fieldops-landing {
    min-height: 100vh;
    padding: clamp(1rem, 4vw, 3rem);
    background: var(--fieldops-page);
    color: var(--fieldops-ink);
}

.fieldops-landing__wrap {
    display: grid;
    width: min(100%, 1120px);
    min-height: calc(100vh - clamp(2rem, 8vw, 6rem));
    align-content: center;
    gap: 1rem;
    margin: 0 auto;
}

.fieldops-landing__hero {
    display: grid;
    grid-template-columns: minmax(0, 1.15fr) minmax(280px, 0.85fr);
    overflow: hidden;
    border: 1px solid var(--fieldops-border);
    border-radius: 8px;
    background: var(--fieldops-white);
    box-shadow: 0 18px 48px rgb(11 23 42 / 12%);
}

.fieldops-landing__copy {
    align-self: center;
    padding: clamp(2rem, 6vw, 5rem);
}

.fieldops-landing__eyebrow {
    margin: 0 0 1rem;
    color: var(--fieldops-blue-dark);
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.15em;
    text-transform: uppercase;
}

.fieldops-landing__copy h1 {
    max-width: 15ch;
    margin: 0;
    color: var(--fieldops-navy);
    font-family: var(--font-brand);
    font-size: clamp(2.7rem, 6vw, 5.2rem);
    font-weight: 800;
    letter-spacing: -0.06em;
    line-height: 1.02;
}

.fieldops-landing__intro {
    max-width: 38rem;
    margin: 1.5rem 0 2rem;
    color: #53617a;
    font-size: 1rem;
    line-height: 1.8;
}

.fieldops-landing__workflow {
    align-self: stretch;
    padding: clamp(2rem, 5vw, 3.5rem);
    background: var(--fieldops-navy);
    color: var(--fieldops-white);
}

.fieldops-landing__workflow .fieldops-landing__eyebrow {
    color: var(--fieldops-orange);
}

.fieldops-landing__workflow h2 {
    margin: 0 0 2rem;
    color: white;
    font-size: clamp(1.8rem, 3.5vw, 2.8rem);
    font-weight: 700;
    letter-spacing: -0.04em;
    line-height: 1.1;
}

.fieldops-landing__workflow ul {
    display: grid;
    gap: 0.15rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.fieldops-landing__workflow li {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    border-top: 1px solid rgb(255 255 255 / 16%);
    padding: 0.9rem 0;
    color: white;
    font-size: 0.9rem;
    font-weight: 600;
}

.fieldops-landing__workflow li span {
    color: var(--fieldops-blue);
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.08em;
}

.fieldops-landing__footer {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.25rem 0.4rem;
    color: #53617a;
    font-size: 0.75rem;
}

@media (max-width: 760px) {
    .fieldops-landing__hero {
        grid-template-columns: minmax(0, 1fr);
    }

    .fieldops-landing__copy {
        padding: 2.5rem clamp(1.5rem, 7vw, 3rem);
    }

    .fieldops-landing__copy h1 {
        font-size: clamp(2.6rem, 11vw, 4rem);
    }
}

@media (max-width: 460px) {
    .fieldops-brand-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .fieldops-brand-tag {
        align-self: flex-start;
    }

    .fieldops-landing__footer {
        flex-direction: column;
        gap: 0.2rem;
    }
}
</style>
