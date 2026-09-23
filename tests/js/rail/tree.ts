/**
 * Trees for the rail's decision functions, written as they read.
 *
 *   f(1, 'Brand', [f(2, 'Logos'), f(3, 'Print')])
 *
 * Only the fields the functions read. `sort_order` defaults to 0, which is
 * what every folder starts life at; `sort_folders` to null, which is "same as
 * everywhere".
 */
export interface TestNode {
    id: number;
    name: string;
    sort_order: number;
    sort_folders: string | null;
    children: TestNode[];
}

export function f(
    id: number,
    name: string,
    children: TestNode[] = [],
    extra: Partial<Pick<TestNode, 'sort_order' | 'sort_folders'>> = {}
): TestNode {
    return { id, name, sort_order: 0, sort_folders: null, children, ...extra };
}

/** Names, depth-first, indented by level: the shape a person sees. */
export function outline(nodes: TestNode[], depth = 0): string[] {
    return nodes.flatMap((node) => [
        '  '.repeat(depth) + node.name,
        ...outline(node.children, depth + 1),
    ]);
}

export function names(nodes: TestNode[]): string[] {
    return nodes.map((node) => node.name);
}
