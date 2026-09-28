import { ButtonHTMLAttributes } from 'react';

export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <button
            {...props}
            className={
                `inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-transparent bg-primary px-4 text-sm font-medium text-primary-foreground shadow-xs transition-colors hover:bg-daiku-yellow-dark focus:outline-none focus-visible:ring-3 focus-visible:ring-ring/50 active:translate-y-px ${
                    disabled && 'opacity-50'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
