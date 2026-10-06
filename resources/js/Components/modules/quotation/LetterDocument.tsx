import { cn } from '@/lib/utils';
import type { CompanyLetter, LetterRow } from '@/types';
import { Fragment } from 'react';

const rupiah = (amount: number) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(amount);

/** Header & total rows of the RAB table — the light blue of the company's letters. */
const HEAD = 'bg-info/15 text-daiku-dark';

const CONTACT_FIELDS = ['address', 'email', 'phone', 'instagram'] as const;

function RowCard({ row }: { row: LetterRow }) {
    return (
        <li className="px-3 py-2.5 text-sm">
            <div className="flex justify-between gap-3">
                <span className="font-medium text-daiku-dark uppercase">
                    {row.no}. {row.description}
                </span>
                <span className="shrink-0 font-medium tabular-nums">{rupiah(row.total)}</span>
            </div>
            <p className="text-xs text-daiku-muted">
                {(row.p || row.t) && `${row.p ?? '–'} × ${row.t ?? '–'} · `}
                {row.volume} {row.unit} × Rp {rupiah(row.unitPrice)}
            </p>
        </li>
    );
}

/**
 * Sprint 15 — the company letter on screen, matching the PDF
 * (resources/views/pdf/layouts/letter.blade.php): letterhead, number &
 * subject, the RAB table (cards on a phone), total in words, numbered
 * "Catatan", signature and the black footer. Pure presentation of the
 * server-built `CompanyLetter`.
 */
export function LetterDocument({ letter, className }: { letter: CompanyLetter; className?: string }) {
    const { company, signer } = letter;

    return (
        <article className={cn('relative overflow-hidden rounded-2xl bg-card shadow-xl shadow-daiku-dark/5 ring-1 ring-daiku-border', className)}>
            {/* Letterhead */}
            <header className="grid sm:grid-cols-[1fr_minmax(0,46%)]">
                <div className="flex items-center px-5 py-4 sm:px-10">
                    {company.logo ? (
                        <img src={company.logo} alt={company.name} className="h-12 max-w-48 object-contain sm:h-14" />
                    ) : (
                        <span className="text-xl font-bold text-daiku-dark">{company.name}</span>
                    )}
                </div>
                <div className="bg-daiku-yellow px-5 py-3 text-xs leading-relaxed text-daiku-dark sm:px-10 sm:text-right">
                    {/* Text, then its icon — the same marks as the PDF (LetterParts::ICONS). */}
                    {CONTACT_FIELDS.map((field) =>
                        company[field] ? (
                            <p key={field} className="flex items-center gap-1.5 sm:justify-end">
                                <span className="order-2 sm:order-1">{company[field]}</span>
                                <img src={company.icons[field]} alt="" aria-hidden className="order-1 size-3 shrink-0 sm:order-2" />
                            </p>
                        ) : null,
                    )}
                </div>
            </header>
            <div className="h-1.5 bg-daiku-dark" />

            {company.logo && (
                <img
                    src={company.logo}
                    alt=""
                    aria-hidden
                    className="pointer-events-none absolute top-1/3 left-1/2 w-2/3 max-w-md -translate-x-1/2 opacity-[0.05] select-none"
                />
            )}

            <div className="relative space-y-4 px-5 py-6 text-sm text-daiku-dark sm:px-10">
                <p className="text-right">{letter.date}</p>

                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                    <dt>Nomor</dt>
                    <dd>
                        :{' '}
                        {letter.draft ? (
                            <>
                                <span className="font-semibold text-error-ink">DRAF</span> — nomor diterbitkan saat penawaran dikirim
                            </>
                        ) : (
                            letter.number
                        )}
                    </dd>
                    <dt>Perihal</dt>
                    <dd>: {letter.subject}</dd>
                    {letter.meta.map((meta) => (
                        <Fragment key={meta.label}>
                            <dt>{meta.label}</dt>
                            <dd>: {meta.value}</dd>
                        </Fragment>
                    ))}
                </dl>

                <p>
                    Kepada,
                    <br />
                    <span className="font-semibold">{letter.recipient}</span>
                </p>

                <p>
                    Dengan hormat,
                    <br />
                    {letter.intro}
                </p>

                {/* Phone: one card per line. */}
                <div className="space-y-3 sm:hidden">
                    {letter.groups.map((group) => (
                        <section key={`${group.label}-${group.name}`}>
                            {letter.showGroups && (
                                <h3 className="mb-1 text-xs font-semibold uppercase">
                                    {group.label}. {group.name}
                                </h3>
                            )}
                            <ul className="divide-y divide-daiku-border rounded-xl ring-1 ring-daiku-border">
                                {group.rows.map((row) => (
                                    <RowCard key={row.no} row={row} />
                                ))}
                                {letter.showGroups && (
                                    <li className="flex justify-between px-3 py-2 text-sm italic">
                                        <span>Subtotal</span>
                                        <span className="tabular-nums">{rupiah(group.subtotal)}</span>
                                    </li>
                                )}
                            </ul>
                        </section>
                    ))}
                </div>

                {/* Tablet & desktop: the letter's table. */}
                <div className="hidden overflow-x-auto sm:block">
                    <table className="w-full border-collapse text-xs">
                        <thead className={HEAD}>
                            <tr>
                                <th rowSpan={2} className="w-10 border border-daiku-dark px-2 py-1">NO</th>
                                <th rowSpan={2} className="border border-daiku-dark px-2 py-1">URAIAN PEKERJAAN</th>
                                <th colSpan={2} className="border border-daiku-dark px-2 py-1">UKURAN</th>
                                <th rowSpan={2} className="w-16 border border-daiku-dark px-2 py-1">VOLUME</th>
                                <th rowSpan={2} className="w-16 border border-daiku-dark px-2 py-1">SATUAN</th>
                                <th className="w-28 border border-daiku-dark px-2 py-1">SATUAN</th>
                                <th className="w-28 border border-daiku-dark px-2 py-1">HARGA</th>
                            </tr>
                            <tr>
                                <th className="w-12 border border-daiku-dark px-2 py-1">P</th>
                                <th className="w-12 border border-daiku-dark px-2 py-1">T</th>
                                <th className="border border-daiku-dark px-2 py-1">(Rp)</th>
                                <th className="border border-daiku-dark px-2 py-1">(Rp)</th>
                            </tr>
                        </thead>
                        <tbody>
                            {letter.groups.map((group) => (
                                <Fragment key={`${group.label}-${group.name}`}>
                                    {letter.showGroups && (
                                        <tr className="bg-daiku-gray font-semibold">
                                            <td className="border border-daiku-dark px-2 py-1 text-center">{group.label}</td>
                                            <td colSpan={7} className="border border-daiku-dark px-2 py-1 uppercase">
                                                {group.name}
                                            </td>
                                        </tr>
                                    )}
                                    {group.rows.map((row) => (
                                        <tr key={row.no}>
                                            <td className="border border-daiku-dark px-2 py-1 text-center">{row.no}</td>
                                            <td className="border border-daiku-dark px-2 py-1 uppercase">{row.description}</td>
                                            <td className="border border-daiku-dark px-2 py-1 text-center">{row.p}</td>
                                            <td className="border border-daiku-dark px-2 py-1 text-center">{row.t}</td>
                                            <td className="border border-daiku-dark px-2 py-1 text-center">{row.volume}</td>
                                            <td className="border border-daiku-dark px-2 py-1 text-center">{row.unit}</td>
                                            <td className="border border-daiku-dark px-2 py-1 text-right tabular-nums">{rupiah(row.unitPrice)}</td>
                                            <td className="border border-daiku-dark px-2 py-1 text-right tabular-nums">{rupiah(row.total)}</td>
                                        </tr>
                                    ))}
                                    {letter.showGroups && (
                                        <tr className="italic">
                                            <td colSpan={7} className="border border-daiku-dark px-2 py-1 text-right">
                                                Subtotal {group.name}
                                            </td>
                                            <td className="border border-daiku-dark px-2 py-1 text-right tabular-nums">{rupiah(group.subtotal)}</td>
                                        </tr>
                                    )}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>

                <dl className={cn('rounded-lg px-3 py-2 text-sm font-semibold', HEAD)}>
                    {letter.totals.map((total) => (
                        <div key={total.label} className="flex justify-between gap-4">
                            <dt>{total.label}</dt>
                            <dd className="tabular-nums">{total.amount < 0 ? `− ${rupiah(-total.amount)}` : rupiah(total.amount)}</dd>
                        </div>
                    ))}
                </dl>
                <p className="font-semibold">TERBILANG: {letter.totalInWords}</p>

                {letter.paymentTerms.length > 0 && (
                    <section>
                        <h3 className="mb-1 font-semibold">Skema Pembayaran</h3>
                        <ul className="divide-y divide-daiku-border rounded-xl ring-1 ring-daiku-border">
                            {letter.paymentTerms.map((term) => (
                                <li key={term.sequence} className="flex flex-wrap items-baseline justify-between gap-x-4 px-3 py-2">
                                    <span>
                                        <span className="font-medium">
                                            {term.sequence}. {term.label}
                                        </span>{' '}
                                        <span className="text-daiku-muted">({term.percentage}%)</span>
                                        <span className="block text-xs text-daiku-muted">{term.when}</span>
                                    </span>
                                    <span className="font-medium tabular-nums">Rp {rupiah(term.amount)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section>
                    <p>Catatan:</p>
                    <ol className="mt-1 list-decimal space-y-1 pl-5">
                        {letter.notes.map((note, index) => (
                            <li key={index}>{note}</li>
                        ))}
                    </ol>
                </section>

                {letter.stamp && (
                    <p>
                        <span className="inline-block border-2 border-success-ink px-3 py-0.5 font-bold tracking-widest text-success-ink">{letter.stamp}</span>
                    </p>
                )}

                <p className="text-xs">{letter.closing}</p>

                <div className="flex justify-end">
                    <div className="w-56">
                        <p>Hormat kami,</p>
                        {signer.signature ? (
                            <img src={signer.signature} alt="Tanda tangan" className="my-1 h-16 object-contain" />
                        ) : (
                            <div className="h-16" />
                        )}
                        <p className="font-semibold">{signer.name || company.name}</p>
                        {signer.title && <p className="text-xs text-daiku-muted">{signer.title}</p>}
                    </div>
                </div>
            </div>

            <footer className="relative bg-daiku-dark px-4 py-2 text-center text-[10px] font-bold tracking-wide text-background">{company.footer}</footer>
        </article>
    );
}
