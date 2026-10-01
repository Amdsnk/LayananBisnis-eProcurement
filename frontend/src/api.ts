let token = "";
export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public fields: Record<string, string[]> = {},
  ) {
    super(message);
  }
}
export async function api<T>(
  path: string,
  method = "GET",
  data?: unknown,
): Promise<T> {
  if (method !== "GET" && !token) {
    const res = await fetch("/api/v1/csrf", {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    });
    token = (await res.json()).token;
  }
  const res = await fetch("/api/v1/" + path, {
    method,
    credentials: "same-origin",
    headers: {
      Accept: "application/json",
      ...(method !== "GET"
        ? {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": token,
            "Idempotency-Key": crypto.randomUUID(),
          }
        : {}),
    },
    body: data === undefined ? undefined : JSON.stringify(data),
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({ message: "Request failed" }));
    if (res.status === 419) token = "";
    throw new ApiError(
      err.message ?? "Request failed",
      res.status,
      err.errors ?? {},
    );
  }
  return res.json();
}
export function clearToken() {
  token = "";
}
export async function upload(path: string, file: File) {
  if (!token)
    await api("csrf").then((x) => {
      token = (x as { token: string }).token;
    });
  const form = new FormData();
  form.append("file", file);
  const res = await fetch("/api/v1/" + path, {
    method: "POST",
    credentials: "same-origin",
    headers: { Accept: "application/json", "X-CSRF-TOKEN": token },
    body: form,
  });
  if (!res.ok) throw new ApiError((await res.json()).message, res.status);
  return res.json();
}
