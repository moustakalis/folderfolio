/**
 * gettext's Plural-Forms expression, evaluated without `eval`.
 *
 * A counted label arrives with every form the site's language has
 * (Support\Plurals on the PHP side) and the translation's own rule for
 * choosing one — `n != 1` for English, three branches for Polish, six for
 * Arabic. This turns the rule into a function once and caches it.
 *
 * The grammar is exactly what core's `Plural_Forms` accepts, with its
 * precedence: `n`, integers, parentheses, `%`, `< <= > >=`, `== !=`, `&&`,
 * `||` and `?:`. The server only ever sends an expression that class parsed,
 * so anything else here is a bug, and it falls back to English's rule rather
 * than throwing into a render.
 */

type Node = (n: number) => number;

const DEFAULT: Node = (n) => (n !== 1 ? 1 : 0);

const cache = new Map<string, Node>();

const TOKEN = /\s*(n\b|\d+|<=|>=|==|!=|&&|\|\||[()?:<>%])/y;

function tokenize(rule: string): string[] {
    const tokens: string[] = [];
    TOKEN.lastIndex = 0;

    while (TOKEN.lastIndex < rule.length) {
        if (/^\s*$/.test(rule.slice(TOKEN.lastIndex))) {
            break;
        }

        const match = TOKEN.exec(rule);

        if (!match) {
            throw new Error(`Unexpected character in plural rule at ${TOKEN.lastIndex}`);
        }

        tokens.push(match[1]);
    }

    return tokens;
}

function parse(rule: string): Node {
    const tokens = tokenize(rule);
    let at = 0;

    const peek = (): string | undefined => tokens[at];
    const take = (expected?: string): string => {
        const token = tokens[at];

        if (token === undefined || (expected !== undefined && token !== expected)) {
            throw new Error(`Expected ${expected ?? 'a token'} in plural rule`);
        }

        at++;

        return token;
    };

    const binary = (next: () => Node, ops: Record<string, (a: number, b: number) => number>): (() => Node) => () => {
        let left = next();

        for (let op = peek(); op !== undefined && op in ops; op = peek()) {
            take();
            const apply = ops[op];
            const l = left;
            const r = next();
            left = (n) => apply(l(n), r(n));
        }

        return left;
    };

    const primary = (): Node => {
        const token = take();

        if (token === 'n') {
            return (n) => n;
        }

        if (token === '(') {
            const inner = ternary();
            take(')');

            return inner;
        }

        if (/^\d+$/.test(token)) {
            const value = Number(token);

            return () => value;
        }

        throw new Error(`Unexpected ${token} in plural rule`);
    };

    const b = (value: boolean): number => (value ? 1 : 0);
    const mod = binary(primary, { '%': (x, y) => (y === 0 ? 0 : x % y) });
    const rel = binary(mod, {
        '<': (x, y) => b(x < y),
        '<=': (x, y) => b(x <= y),
        '>': (x, y) => b(x > y),
        '>=': (x, y) => b(x >= y),
    });
    const eq = binary(rel, { '==': (x, y) => b(x === y), '!=': (x, y) => b(x !== y) });
    const and = binary(eq, { '&&': (x, y) => b(x !== 0 && y !== 0) });
    const or = binary(and, { '||': (x, y) => b(x !== 0 || y !== 0) });

    function ternary(): Node {
        const test = or();

        if (peek() !== '?') {
            return test;
        }

        take('?');
        const yes = ternary();
        take(':');
        const no = ternary();

        return (n) => (test(n) !== 0 ? yes(n) : no(n));
    }

    const root = ternary();

    if (at !== tokens.length) {
        throw new Error('Trailing tokens in plural rule');
    }

    return root;
}

/**
 * Which form a count takes under `rule` — 0 for English's singular.
 *
 * `rule` is the expression alone (`n != 1`), not the whole header.
 */
export function pluralIndex(count: number, rule: string | undefined): number {
    // The server strips a header's closing `;` as core does; so does this.
    const key = (rule ?? '').trim().replace(/;\s*$/, '');
    let fn = cache.get(key);

    if (fn === undefined) {
        try {
            fn = key === '' ? DEFAULT : parse(key);
        } catch {
            fn = DEFAULT;
        }

        cache.set(key, fn);
    }

    const index = fn(Math.abs(Math.trunc(count)));

    return Number.isFinite(index) && index >= 0 ? index : 0;
}

/** The form of `forms` a count takes — the last one if the rule points past the end. */
export function pickForm(forms: readonly string[], count: number, rule: string | undefined): string {
    return forms[Math.min(pluralIndex(count, rule), forms.length - 1)] ?? '';
}
