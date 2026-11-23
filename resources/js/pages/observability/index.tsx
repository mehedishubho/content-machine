import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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

const PERIODS = [
    { value: 'today', label: 'Today', aria: 'Publish period: Today' },
    { value: '7', label: '7 days', aria: 'Publish period: 7 days' },
    { value: '30', label: '30 days', aria: 'Publish period: 30 days' },
    { value: '90', label: '90 days', aria: 'Publish period: 90 days' },
];

// Outcome pills pair a semantic color class with a text label — never
// color alone (UI-SPEC accessibility contract).
const OUTCOME_PILLS: Record<string, { pillClass: string; label: string }> = {
    succeeded: { pillClass: 'pill st-succeeded', label: 'Succeeded' },
    failed: { pillClass: 'pill st-failed', label: 'Failed' },
    retrying: { pillClass: 'pill st-retrying', label: 'Retrying' },
    lease: { pillClass: 'pill st-lease', label: 'Lease expired' },
};

function rateLabel(rate: number | null): string {
    return rate === null ? '\u2014' : `${rate}%`;
}

function timeLabel(at: string | null, timezone: string): string {
    if (!at) {
        return '';
    }

    const date = new Date(at);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone,
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

function OutcomePill({ outcome }: { outcome: string | null }) {
    const pill = outcome ? OUTCOME_PILLS[outcome] : undefined;

    if (!pill) {
        return null;
    }

    return <span className={pill.pillClass}>{pill.label}</span>;
}

export default function Observability({ metrics }: PageProps) {
    const [reloading, setReloading] = useState(false);
    const [loadFailed, setLoadFailed] = useState(false);

    // Period state rides the URL query so a period is shareable and
    // reloadable; the partial reload fetches the metrics props only and
    // keeps the layout mounted (dimmed to 60%, no skeleton swap).
    function switchPeriod(period: string) {
        setLoadFailed(false);
        router.get(
            index.url({ query: { period } }),
            {},
            {
                only: ['metrics'],
                preserveState: true,
                preserveScroll: true,
                onStart: () => setReloading(true),
                onFinish: () => setReloading(false),
                onError: () => {
                    setReloading(false);
                    setLoadFailed(true);
                },
            },
        );
    }

    const finalized = metrics.succeeded + metrics.failed;

    return (
        <>
            <Head title="Observability" />

            <div className="studio-page obs-page flex min-h-full flex-1 flex-col">
                <div className="home-head">
                    <h2 className="home-h">Observability</h2>
                </div>
                <p className="dash-lead">
                    How reliably content is reaching its platforms.
                </p>

                <div className="tabbar obs-tabbar" role="tablist">
                    {PERIODS.map((period) => {
                        const active = metrics.period === period.value;

                        return (
                            <button
                                key={period.value}
                                type="button"
                                role="tab"
                                aria-selected={active}
                                aria-label={period.aria}
                                onClick={() => switchPeriod(period.value)}
                            >
                                {period.label}
                            </button>
                        );
                    })}
                </div>

                {loadFailed && (
                    <Alert className="obs-error">
                        <AlertTitle className="obs-error-t">
                            Could not load publish metrics.
                        </AlertTitle>
                        <AlertDescription>
                            Check the publish log channel in Railway, then
                            retry.
                            <Button
                                variant="outline"
                                size="sm"
                                className="obs-retry"
                                onClick={() => switchPeriod(metrics.period)}
                            >
                                Retry
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}

                <div
                    className={
                        reloading ? 'obs-metrics is-reloading' : 'obs-metrics'
                    }
                >
                    <h3 className="obs-section-h">{metrics.period_label}</h3>

                    <div className="stats">
                        <div className="stat s-succeeded">
                            <div className="n">{metrics.succeeded}</div>
                            <div className="k">Published</div>
                        </div>
                        <div
                            className={
                                metrics.failed > 0 ? 'stat s-failed' : 'stat'
                            }
                        >
                            <div className="n">{metrics.failed}</div>
                            <div className="k">Failed</div>
                        </div>
                        <div className="stat s-warn">
                            <div className="n">{metrics.retries}</div>
                            <div className="k">Retries</div>
                        </div>
                        <div className="stat s-warn">
                            <div className="n">{metrics.lease_expiries}</div>
                            <div className="k">Lease expiries</div>
                        </div>
                    </div>

                    <section className="obs-rate">
                        <div className="obs-rate-figure">
                            <span
                                className={
                                    metrics.rate === null
                                        ? 'obs-rate-n is-empty'
                                        : 'obs-rate-n'
                                }
                            >
                                {rateLabel(metrics.rate)}
                            </span>
                            {metrics.rate === null && (
                                <span className="sr-only">No attempts</span>
                            )}
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
                                        style={{
                                            width: `${(metrics.succeeded / finalized) * 100}%`,
                                        }}
                                    />
                                    <div
                                        className="obs-bar-seg obs-bar-bad"
                                        style={{
                                            width: `${(metrics.failed / finalized) * 100}%`,
                                        }}
                                    />
                                </>
                            )}
                        </div>
                    </section>

                    {metrics.attempts === 0 ? (
                        <section className="obs-empty">
                            <h3>No publish activity in this period.</h3>
                            <p>
                                Publish outcomes appear here once posts or
                                videos go out through PostSyncer or SocialReins.
                            </p>
                        </section>
                    ) : metrics.recent_outcomes.length === 0 ? (
                        <p className="dash-upcoming-empty">
                            No publish outcomes yet. Outcomes appear here after
                            the first publish attempt.
                        </p>
                    ) : (
                        <section className="dash-upcoming">
                            <div className="dash-upcoming-h">
                                <h3>Recent publish outcomes</h3>
                            </div>
                            <ul>
                                {metrics.recent_outcomes.map((outcome) => (
                                    <li key={outcome.id}>
                                        <div className="obs-outcome">
                                            <span
                                                className="obs-outcome-id"
                                                title={outcome.human_id ?? ''}
                                            >
                                                {outcome.human_id ?? ''}
                                            </span>
                                            <span className="obs-outcome-main">
                                                <span
                                                    className="obs-outcome-title"
                                                    title={
                                                        outcome.title ??
                                                        outcome.human_id ??
                                                        ''
                                                    }
                                                >
                                                    {outcome.title ??
                                                        outcome.human_id ??
                                                        ''}
                                                </span>
                                                <span className="obs-outcome-meta">
                                                    {outcome.type}
                                                    {outcome.provider
                                                        ? ` \u00b7 ${outcome.provider}`
                                                        : ''}
                                                </span>
                                            </span>
                                            <span className="obs-outcome-side">
                                                <OutcomePill
                                                    outcome={outcome.outcome}
                                                />
                                                <span className="obs-outcome-when">
                                                    {timeLabel(
                                                        outcome.created_at,
                                                        metrics.timezone,
                                                    )}
                                                </span>
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            {metrics.recent_outcomes.length === 20 && (
                                <p className="obs-cap">
                                    Showing the latest 20 outcomes
                                </p>
                            )}
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}

Observability.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: home() }],
};
