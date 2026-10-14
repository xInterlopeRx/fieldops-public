<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="fieldops-guest-shell">
        <div class="fieldops-guest-wrap">
            <header class="fieldops-brand-header">
                <Link href="/" class="fieldops-brand-lockup" aria-label="FieldOps Industrial Networks home">
                    <span class="fieldops-brand-name">byte<span>Harder</span></span>
                    <span class="fieldops-brand-subtitle">Industrial Networks</span>
                </Link>
                <span class="fieldops-brand-tag">Internal field operations</span>
            </header>

            <main class="fieldops-auth-frame">
                <section class="fieldops-auth-story" aria-labelledby="fieldops-auth-heading">
                    <p class="fieldops-auth-eyebrow">FieldOps / Operations</p>
                    <h1 id="fieldops-auth-heading">
                        Field work,<br />
                        <span>in good hands.</span>
                    </h1>
                    <p class="fieldops-auth-copy">
                        Lorem ipsum dolor sit amet, consectetur adipiscing elit.
                        Nunc vitae justo non nulla facilisis iaculis. Curabitur
                        at dui sed erat consequat posuere.
                    </p>
                    <ul class="fieldops-auth-workflow">
                        <li><span>01</span> Monthly schedule</li>
                        <li><span>02</span> Customer and site history</li>
                        <li><span>03</span> Jobs, visits, and notes</li>
                    </ul>
                </section>

                <section class="fieldops-auth-panel">
                    <slot />
                </section>
            </main>

            <footer class="fieldops-auth-footer">
                FieldOps Industrial Networks <span aria-hidden="true">·</span> Midland, Texas
            </footer>
        </div>
    </div>
</template>

<style scoped>
.fieldops-guest-shell {
    display: flex;
    min-height: 100vh;
    justify-content: center;
    padding: clamp(1rem, 4vw, 3rem);
    background: var(--fieldops-page);
    color: var(--fieldops-ink);
}

.fieldops-guest-wrap {
    display: grid;
    width: min(100%, 1120px);
    align-content: center;
    gap: 1rem;
}

.fieldops-auth-frame {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(320px, 0.95fr);
    overflow: hidden;
    border: 1px solid var(--fieldops-border);
    border-radius: 8px;
    background: var(--fieldops-white);
    box-shadow: 0 18px 48px rgb(11 23 42 / 12%);
}

.fieldops-auth-story {
    padding: clamp(2rem, 5vw, 4.5rem);
    background: var(--fieldops-navy);
    color: var(--fieldops-white);
}

.fieldops-auth-eyebrow {
    margin: 0 0 1.1rem;
    color: var(--fieldops-orange);
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.15em;
    text-transform: uppercase;
}

.fieldops-auth-story h1 {
    margin: 0;
    font-size: clamp(2.5rem, 5vw, 4.6rem);
    font-weight: 800;
    font-family: var(--font-brand);
    letter-spacing: -0.055em;
    line-height: 1.02;
}

.fieldops-auth-story h1 span {
    color: var(--fieldops-blue);
}

.fieldops-auth-copy {
    max-width: 36rem;
    margin: 1.5rem 0 2rem;
    color: #d7e0ef;
    font-size: 1rem;
    line-height: 1.75;
}

.fieldops-auth-workflow {
    display: grid;
    gap: 0.15rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.fieldops-auth-workflow li {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    border-top: 1px solid rgb(255 255 255 / 16%);
    padding: 0.8rem 0;
    color: white;
    font-size: 0.9rem;
    font-weight: 600;
}

.fieldops-auth-workflow li span {
    color: var(--fieldops-orange);
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.08em;
}

.fieldops-auth-panel {
    align-self: center;
    padding: clamp(1.5rem, 4vw, 3.5rem);
    background: var(--fieldops-white);
}

.fieldops-auth-footer {
    color: #53617a;
    font-size: 0.75rem;
    text-align: center;
}

.fieldops-auth-footer span {
    padding: 0 0.25rem;
}

@media (max-width: 760px) {
    .fieldops-auth-frame {
        grid-template-columns: minmax(0, 1fr);
    }

    .fieldops-auth-story {
        padding: 2rem clamp(1.5rem, 7vw, 3rem);
    }

    .fieldops-auth-story h1 {
        font-size: clamp(2.3rem, 11vw, 3.6rem);
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
}

</style>
