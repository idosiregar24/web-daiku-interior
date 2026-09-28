import { cn } from '@/lib/utils';

interface SparklineProps {
    /** Oldest → newest. Fewer than two points renders nothing. */
    data: number[];
    /** Screen-reader summary, e.g. "Pemasukan 6 bulan terakhir". */
    ariaLabel?: string;
    width?: number;
    height?: number;
    className?: string;
}

/**
 * Tiny trend line for StatCard (dataviz "stat tile" contract): the line
 * sits in the de-emphasis hue, the current period is the one accented
 * dot, with a 2px surface ring so it reads where it crosses the line.
 * Values themselves live in the tile/table — this only shows direction.
 */
export function Sparkline({ data, ariaLabel, width = 96, height = 32, className }: SparklineProps) {
    if (data.length < 2) {
        return null;
    }

    const pad = 4;
    const min = Math.min(...data);
    const max = Math.max(...data);
    const span = max - min || 1;
    const points = data.map((value, index) => [
        pad + (index * (width - pad * 2)) / (data.length - 1),
        height - pad - ((value - min) / span) * (height - pad * 2),
    ]);
    const path = points.map(([x, y], index) => `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ');
    const [lastX, lastY] = points[points.length - 1];

    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            role={ariaLabel ? 'img' : undefined}
            aria-label={ariaLabel}
            aria-hidden={ariaLabel ? undefined : true}
            className={cn('shrink-0 overflow-visible', className)}
        >
            <path
                d={path}
                fill="none"
                className="stroke-viz-axis/70"
                strokeWidth={2}
                strokeLinejoin="round"
                strokeLinecap="round"
            />
            <circle cx={lastX} cy={lastY} r={4} className="fill-viz-1 stroke-card" strokeWidth={2} />
        </svg>
    );
}
