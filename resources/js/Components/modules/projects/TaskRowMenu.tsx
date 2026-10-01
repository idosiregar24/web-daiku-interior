import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import type { Task } from '@/types';
import { MoreHorizontal, Pencil, Trash2 } from 'lucide-react';

interface TaskRowMenuProps {
    task: Task;
    onEdit: (task: Task) => void;
    onDelete: (task: Task) => void;
}

/**
 * PM row actions for a task (Sprint 9 "Edit/Hapus Task"). Mirrors
 * TaskService's rules so the menu doesn't offer what the server refuses:
 * a DONE task whose wage is paid can't be edited, a DONE task can't be
 * deleted. The history guard on delete is checked server-side only (see
 * TaskDeleteDialog).
 */
export function TaskRowMenu({ task, onEdit, onDelete }: TaskRowMenuProps) {
    const isDone = task.status === 'DONE';
    const isPaid = isDone && !!task.is_wage_paid;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={`Aksi untuk task ${task.title}`}>
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-auto min-w-44">
                <DropdownMenuItem disabled={isPaid} onSelect={() => onEdit(task)}>
                    <Pencil />
                    {isPaid ? 'Terkunci (upah dibayar)' : 'Edit Task'}
                </DropdownMenuItem>
                {!isDone && (
                    <DropdownMenuItem variant="destructive" onSelect={() => onDelete(task)}>
                        <Trash2 />
                        Hapus Task
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
