/**
 * Making and changing a smart folder — tier 3 item 13 (13a: saved rules, all
 * of them must match; media's rules first).
 *
 * A modal `<dialog>`: the rules are a small form with a live answer, and the
 * browser's own modal gives it focus containment, Escape and the top layer
 * without a line of focus management here. One rule a line — what it is
 * about, how, and the value — and a count under them that re-asks the server
 * as the rules change (`/smart/preview`), so "does this say what I mean" is
 * answered before anything is saved.
 *
 * The server is the authority on what a rule may say (`Domain\SmartRules`);
 * this offers only what it accepts.
 */

import { useEffect, useMemo, useRef, useState } from 'react';

import { CloseIcon, PlusIcon } from './icons';
import {
    previewSmart,
    useDeleteSmart,
    useSaveSmart,
    type FolderNode,
    type SmartFolder,
    type SmartRule,
} from './queries';
import { useRail } from './store';
import { errorMessage, t, tn } from '../../core/api';

type Field = SmartRule['field'];

const FIELDS: Field[] = ['type', 'date', 'author', 'size', 'filed', 'name'];

const MB = 1024 * 1024;
const UNITS = [
    { key: 'KB', bytes: 1024 },
    { key: 'MB', bytes: MB },
    { key: 'GB', bytes: 1024 * MB },
] as const;

type Unit = (typeof UNITS)[number];

/** The unit a stored size is shown in: the largest it reaches, KB at least. */
export function unitFor(bytes: number): Unit {
    return [...UNITS].reverse().find((u) => bytes >= u.bytes) ?? UNITS[0];
}

/** A size in a unit, as a person would write it: at most two decimals. */
export function sizeText(bytes: number, unit: Unit): string {
    return String(Math.round((bytes / unit.bytes) * 100) / 100);
}

/**
 * The Size rule's number and unit — review L13.
 *
 * The unit was worked out again from the byte value on every render, so the
 * field fought the person typing: "1.5" became 15, 2048 KB snapped to 2 MB,
 * the field could not be emptied, and a value from the API showed as
 * 0.19073486328125 MB. The typed text and the chosen unit are this
 * component's; bytes are worked out from them only when one changes, and a
 * size that arrives from outside (another smart folder opened) starts it
 * again.
 */
function SizeValue({
    bytes,
    label,
    unitLabel,
    onChange,
}: {
    bytes: number;
    label: string;
    unitLabel: string;
    onChange: (value: number) => void;
}) {
    const [unit, setUnit] = useState<Unit>(() => unitFor(bytes));
    const [text, setText] = useState(() => sizeText(bytes, unitFor(bytes)));
    const sent = useRef(bytes);

    useEffect(() => {
        if (bytes !== sent.current) {
            sent.current = bytes;
            const next = unitFor(bytes);
            setUnit(next);
            setText(sizeText(bytes, next));
        }
    }, [bytes]);

    function send(value: string, inUnit: Unit) {
        const number = Number.parseFloat(value.replace(',', '.'));
        const next = Number.isFinite(number) && number > 0 ? Math.round(number * inUnit.bytes) : 0;
        sent.current = next;
        onChange(next);
    }

    return (
        <span className="folderfolio-smart-rule__pair">
            <input
                aria-label={label}
                type="text"
                inputMode="decimal"
                value={text}
                onChange={(event) => {
                    setText(event.target.value);
                    send(event.target.value, unit);
                }}
            />
            <select
                aria-label={unitLabel}
                value={unit.key}
                onChange={(event) => {
                    // The number stays and means the new unit: "2" and MB is 2 MB.
                    const next = UNITS.find((u) => u.key === event.target.value) ?? UNITS[1];
                    setUnit(next);
                    send(text, next);
                }}
            >
                {UNITS.map((u) => (
                    <option key={u.key} value={u.key}>
                        {u.key}
                    </option>
                ))}
            </select>
        </span>
    );
}

function fieldLabel(field: Field): string {
    switch (field) {
        case 'type':
            return t('smartFieldType', 'Type');
        case 'date':
            return t('smartFieldDate', 'Uploaded');
        case 'author':
            return t('smartFieldAuthor', 'Uploaded by');
        case 'size':
            return t('smartFieldSize', 'Size');
        case 'filed':
            return t('smartFieldFiled', 'Folder');
        case 'name':
            return t('smartFieldName', 'Name');
    }
}

function ops(field: Field): Array<{ value: string; label: string }> {
    switch (field) {
        case 'type':
            return [
                { value: 'is', label: t('smartOpIs', 'is') },
                { value: 'is_not', label: t('smartOpIsNot', 'is not') },
            ];
        case 'date':
            return [
                { value: 'last', label: t('smartOpLast', 'in the last') },
                { value: 'after', label: t('smartOpAfter', 'on or after') },
                { value: 'before', label: t('smartOpBefore', 'before') },
            ];
        case 'author':
            return [{ value: 'is', label: t('smartOpIs', 'is') }];
        case 'size':
            return [
                { value: 'gt', label: t('smartOpLarger', 'larger than') },
                { value: 'lt', label: t('smartOpSmaller', 'smaller than') },
            ];
        case 'filed':
            return [
                { value: 'none', label: t('smartOpNone', 'is none') },
                { value: 'any', label: t('smartOpAny', 'is any') },
                { value: 'in', label: t('smartOpIn', 'is within') },
            ];
        case 'name':
            return [{ value: 'contains', label: t('smartOpContains', 'contains') }];
    }
}

/** A new rule of a kind, with a value that already means something. */
function fresh(field: Field): SmartRule {
    switch (field) {
        case 'type':
            return { field, op: 'is', value: 'image' };
        case 'date':
            return { field, op: 'last', value: 30 };
        case 'author':
            return { field, op: 'is', value: 'me' };
        case 'size':
            return { field, op: 'gt', value: 5 * MB };
        case 'filed':
            return { field, op: 'none', value: '' };
        case 'name':
            return { field, op: 'contains', value: '' };
    }
}

function today(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

/** Every folder, depth-first, indented — the same order as the tree. */
function flatten(nodes: readonly FolderNode[], depth = 0, out: Array<{ id: number; label: string }> = []) {
    for (const node of nodes) {
        out.push({ id: node.id, label: `${'   '.repeat(depth)}${node.name}` });
        flatten(node.children, depth + 1, out);
    }

    return out;
}

/** Whether a rule is complete enough to send. An empty name box is not a rule yet. */
function usable(rule: SmartRule): boolean {
    if (rule.field === 'name') {
        return String(rule.value).trim() !== '';
    }

    if (rule.field === 'filed' && rule.op === 'in') {
        return Number(rule.value) > 0;
    }

    if (rule.field === 'date' && rule.op !== 'last') {
        return /^\d{4}-\d{2}-\d{2}$/.test(String(rule.value));
    }

    return true;
}

export function SmartEditor({
    smart,
    nodes,
    onClose,
}: {
    smart: SmartFolder | null;
    nodes: readonly FolderNode[];
    onClose: () => void;
}) {
    const dialog = useRef<HTMLDialogElement>(null);
    const [name, setName] = useState(smart?.name ?? '');
    const [rules, setRules] = useState<SmartRule[]>(
        smart?.rules.length ? smart.rules : [fresh('type'), fresh('filed')]
    );
    const [count, setCount] = useState<number | null>(smart?.count ?? null);
    const [counting, setCounting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [people, setPeople] = useState<Array<{ id: number; name: string }>>([]);

    const save = useSaveSmart();
    const remove = useDeleteSmart();
    const selectSmart = useRail((s) => s.selectSmart);
    const folders = useMemo(() => flatten(nodes), [nodes]);
    const complete = rules.filter(usable);

    useEffect(() => {
        const el = dialog.current;

        if (el && !el.open) {
            el.showModal();
        }
    }, []);

    // Whoever this site's list of users shows this person. An Editor without
    // list_users sees the authors of published posts — core's own rule.
    useEffect(() => {
        let live = true;

        window.wp
            ?.apiFetch<Array<{ id: number; name: string }>>({ path: '/wp/v2/users?per_page=100&_fields=id,name' })
            .then((users) => live && setPeople(Array.isArray(users) ? users : []))
            .catch(() => undefined);

        return () => {
            live = false;
        };
    }, []);

    // The live count, a moment after the rules stop changing.
    const signature = JSON.stringify(complete);

    useEffect(() => {
        if (complete.length === 0) {
            setCount(null);

            return;
        }

        let live = true;
        setCounting(true);

        const timer = setTimeout(() => {
            previewSmart(complete)
                .then((n) => live && setCount(n))
                .catch(() => live && setCount(null))
                .finally(() => live && setCounting(false));
        }, 350);

        return () => {
            live = false;
            clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [signature]);

    const update = (index: number, next: SmartRule) =>
        setRules((current) => current.map((rule, i) => (i === index ? next : rule)));

    const submit = async () => {
        setError(null);

        try {
            const saved = await save.mutateAsync({ id: smart?.id ?? null, name: name.trim(), rules: complete });
            selectSmart(saved.id);
            dialog.current?.close();
        } catch (failure) {
            setError(errorMessage(failure));
        }
    };

    const destroy = async () => {
        if (!smart) {
            return;
        }

        setError(null);

        try {
            await remove.mutateAsync(smart.id);
            dialog.current?.close();
        } catch (failure) {
            setError(errorMessage(failure));
        }
    };

    const busy = save.isPending || remove.isPending;

    return (
        <dialog
            ref={dialog}
            className="folderfolio folderfolio-smart-editor"
            aria-labelledby="folderfolio-smart-title"
            onClose={onClose}
        >
            <form
                className="folderfolio-smart-editor__form"
                method="dialog"
                onSubmit={(event) => {
                    event.preventDefault();
                    void submit();
                }}
            >
                <header className="folderfolio-smart-editor__head">
                    <h2 id="folderfolio-smart-title">
                        {smart ? t('editSmartFolder', 'Edit smart folder') : t('newSmartFolder', 'New smart folder')}
                    </h2>
                    <button
                        type="button"
                        className="folderfolio-smart-editor__close"
                        aria-label={t('cancel', 'Cancel')}
                        onClick={() => dialog.current?.close()}
                    >
                        <CloseIcon size={14} />
                    </button>
                </header>

                <label className="folderfolio-smart-editor__name">
                    <span>{t('smartName', 'Name')}</span>
                    <input
                        type="text"
                        value={name}
                        maxLength={191}
                        required
                        placeholder={t('smartNameHint', 'For example: Unfiled images this month')}
                        onChange={(event) => setName(event.target.value)}
                    />
                </label>

                <fieldset className="folderfolio-smart-editor__rules">
                    <legend>{t('smartMatchAll', 'Shows what matches every rule')}</legend>

                    {rules.map((rule, index) => (
                        <div className="folderfolio-smart-rule" key={index}>
                            <select
                                aria-label={t('smartRuleField', 'Rule %s: about', String(index + 1))}
                                value={rule.field}
                                onChange={(event) => update(index, fresh(event.target.value as Field))}
                            >
                                {FIELDS.map((field) => (
                                    <option key={field} value={field}>
                                        {fieldLabel(field)}
                                    </option>
                                ))}
                            </select>

                            <select
                                aria-label={t('smartRuleOp', 'Rule %s: how', String(index + 1))}
                                value={rule.op}
                                onChange={(event) => {
                                    const op = event.target.value;
                                    const value =
                                        rule.field === 'date'
                                            ? op === 'last'
                                                ? 30
                                                : today()
                                            : rule.field === 'filed'
                                              ? op === 'in'
                                                  ? folders[0]?.id ?? ''
                                                  : ''
                                              : rule.value;
                                    update(index, { ...rule, op, value });
                                }}
                            >
                                {ops(rule.field).map((op) => (
                                    <option key={op.value} value={op.value}>
                                        {op.label}
                                    </option>
                                ))}
                            </select>

                            <Value
                                rule={rule}
                                index={index}
                                folders={folders}
                                people={people}
                                onChange={(value) => update(index, { ...rule, value })}
                            />

                            <button
                                type="button"
                                className="folderfolio-smart-rule__remove"
                                aria-label={t('smartRemoveRule', 'Remove rule %s', String(index + 1))}
                                disabled={rules.length === 1}
                                onClick={() => setRules((current) => current.filter((_, i) => i !== index))}
                            >
                                <CloseIcon size={12} />
                            </button>
                        </div>
                    ))}

                    {rules.length < 10 ? (
                        <button
                            type="button"
                            className="folderfolio-smart-editor__add"
                            onClick={() => setRules((current) => [...current, fresh('date')])}
                        >
                            <PlusIcon size={12} />
                            {t('smartAddRule', 'Add a rule')}
                        </button>
                    ) : null}
                </fieldset>

                <p className="folderfolio-smart-editor__count" aria-live="polite">
                    {complete.length === 0
                        ? t('smartNeedsRule', 'Add a rule to see what it matches.')
                        : count === null
                          ? counting
                              ? t('smartCounting', 'Counting…')
                              : ''
                          : tn('smartMatchesOne', 'smartMatchesMany', count, 'Matches %s file', 'Matches %s files', count)}
                </p>

                {error ? (
                    <p className="folderfolio-smart-editor__error" role="alert">
                        {error}
                    </p>
                ) : null}

                <footer className="folderfolio-smart-editor__actions">
                    {smart ? (
                        confirmDelete ? (
                            <span className="folderfolio-smart-editor__confirm">
                                <span>{t('smartDeleteAsk', 'Delete this smart folder? No files are touched.')}</span>
                                <button
                                    type="button"
                                    className="button folderfolio-smart-editor__delete"
                                    disabled={busy}
                                    onClick={() => void destroy()}
                                >
                                    {t('delete', 'Delete')}
                                </button>
                                <button type="button" className="button" onClick={() => setConfirmDelete(false)}>
                                    {t('keep', 'Keep')}
                                </button>
                            </span>
                        ) : (
                            <button
                                type="button"
                                className="button-link folderfolio-smart-editor__delete-link"
                                onClick={() => setConfirmDelete(true)}
                            >
                                {t('delete', 'Delete')}
                            </button>
                        )
                    ) : (
                        <span />
                    )}

                    <span className="folderfolio-smart-editor__buttons">
                        <button type="button" className="button" onClick={() => dialog.current?.close()}>
                            {t('cancel', 'Cancel')}
                        </button>
                        <button
                            type="submit"
                            className="button button-primary"
                            disabled={busy || name.trim() === '' || complete.length === 0}
                        >
                            {t('save', 'Save')}
                        </button>
                    </span>
                </footer>
            </form>
        </dialog>
    );
}

function Value({
    rule,
    index,
    folders,
    people,
    onChange,
}: {
    rule: SmartRule;
    index: number;
    folders: Array<{ id: number; label: string }>;
    people: Array<{ id: number; name: string }>;
    onChange: (value: number | string) => void;
}) {
    const label = t('smartRuleValue', 'Rule %s: value', String(index + 1));

    switch (rule.field) {
        case 'type':
            return (
                <select aria-label={label} value={String(rule.value)} onChange={(event) => onChange(event.target.value)}>
                    <option value="image">{t('smartTypeImage', 'an image')}</option>
                    <option value="video">{t('smartTypeVideo', 'a video')}</option>
                    <option value="audio">{t('smartTypeAudio', 'audio')}</option>
                    <option value="document">{t('smartTypeDocument', 'a document')}</option>
                </select>
            );

        case 'date':
            return rule.op === 'last' ? (
                <span className="folderfolio-smart-rule__pair">
                    <input
                        aria-label={label}
                        type="number"
                        min={1}
                        max={36500}
                        value={String(rule.value)}
                        onChange={(event) => onChange(Math.max(1, Number.parseInt(event.target.value, 10) || 1))}
                    />
                    <span>{t('smartDays', 'days')}</span>
                </span>
            ) : (
                <input aria-label={label} type="date" value={String(rule.value)} onChange={(event) => onChange(event.target.value)} />
            );

        case 'author':
            return (
                <select aria-label={label} value={String(rule.value)} onChange={(event) => onChange(event.target.value === 'me' ? 'me' : Number(event.target.value))}>
                    <option value="me">{t('smartMe', 'me — whoever is looking')}</option>
                    {people.map((person) => (
                        <option key={person.id} value={person.id}>
                            {person.name}
                        </option>
                    ))}
                </select>
            );

        case 'size':
            return (
                <SizeValue
                    bytes={Number(rule.value) || 0}
                    label={label}
                    unitLabel={t('smartSizeUnit', 'Rule %s: unit', String(index + 1))}
                    onChange={onChange}
                />
            );

        case 'filed':
            return rule.op === 'in' ? (
                <select aria-label={label} value={String(rule.value)} onChange={(event) => onChange(Number(event.target.value))}>
                    {folders.map((folder) => (
                        <option key={folder.id} value={folder.id}>
                            {folder.label}
                        </option>
                    ))}
                </select>
            ) : (
                <span />
            );

        case 'name':
            return (
                <input
                    aria-label={label}
                    type="text"
                    maxLength={100}
                    value={String(rule.value)}
                    placeholder={t('smartNameContains', 'For example: logo')}
                    onChange={(event) => onChange(event.target.value)}
                />
            );
    }
}
