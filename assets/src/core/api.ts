export interface ApiEnvelope<T> {
  success: boolean;
  data: T;
}

export interface Folder {
  id: number;
  parent_id: number | null;
  name: string;
  slug: string | null;
  color: string | null;
  icon: string | null;
  sort_order: number;
  children: Folder[];
}

type ApiFetchOptions = {
  method?: string;
  data?: unknown;
};

// WordPress wp.apiFetch global
declare const wp: {
  apiFetch: <T>(options: { path: string } & ApiFetchOptions) => Promise<T>;
};

export function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  return wp.apiFetch<T>({
    path: `/folderfolio/v1${path}`,
    ...options,
  });
}
