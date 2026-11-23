import { Head } from '@inertiajs/react';
import { home } from '@/routes/dashboard';
import { index } from '@/routes/dashboard/observability';

type Outcome = {
    id: number;
    outcome: string | null;
    type: string;
    provider: string | null;
    human_id: string | null;
    title: string | null;
    created_at: string | null;
};

type Metrics = {
    period: string;
    period_label: string;
    timezone: string;
    attempts: number;
    succeeded: number;
    failed: number;
    retries: number;
    lease_expiries: number;
    rate: number | null;
    recent_outcomes: Outcome[];
};

type PageProps = {
    metrics: Metrics;
};

function rateLabel(rate: number | null): string {
    return rate === null ? '\u2014' : `${rate}%`;
}

function StatTile({ label, value, tone }: { label: string; value: number; tone: 'good' | 'bad' | 'warn' | 'none' }) {
    const toneClass =
        tone === 'good'
            ? 'obs-tile-good'
            : tone === 'bad'
              ? 'obs-tile-bad'
              : tone === 'warn'
                ? 'obs-tile-warn'
                : undefined;

    return (
        <div className={`stat${toneClass ? ` ${toneClass}` : ''}`}>
            <div className="n">{value}</div>
            <div className="k">{label}</div>
        </div>
    );
}

export default function Observability({ metrics }: PageProps) {
    const finalized = metrics.succeeded + metrics.failed;

    return (
        <>
            <Head title="Observability" />

            <div className="studio-page obs-page flex min-h-full flex-1 flex-col">
                <div className="home-head">
                    <h2 className="home-h">Observability</h2>
                </div>
                <p className="dash-lead">How reliably content is reaching its platforms.</p>

                <div className="stats">
                    <StatTile label="Published" value={metrics.succeeded} tone="good" />
                    <StatTile label="Failed" value={metrics.failed} tone={metrics.failed > 0 ? 'bad' : 'none'} />
                    <StatTile label="Retries" value={metrics.retries} tone="warn" />
                    <StatTile label="Lease expiries" value={metrics.lease_expiries} tone="warn" />
                </div>

                <section className="obs-rate">
                    <div className="obs-rate-figure">
                        <span className="obs-rate-n">{rateLabel(metrics.rate)}</span>
                        {metrics.rate === null && <span className="sr-only">No attempts</span>}
                    </div>
                    <div
                        className="obs-bar"
                        role="img"
                        aria-label={`Publish success rate: ${rateLabel(metrics.rate)}`}
                    >
                        {finalized > 0 && (
                            <>
                                <div
                                    className="obs-bar-seg obs-bar-good"
                                    style={{ width: `${(metrics.succeeded / finalized) * 100}%` }}
                                />
                                <div
                                    className="obs-bar-seg obs-bar-bad"
                                    style={{ width: `${(metrics.failed / finalized) * 100}%` }}
                                />
                            </>
                        )}
                    </div>
                </section>
            </div>
        </>
    );
}

Observability.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: home() }],
};
