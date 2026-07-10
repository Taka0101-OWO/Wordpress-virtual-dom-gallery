const revealed = new Set<string>();

export function hasRevealed(id: string): boolean {
  return revealed.has(id);
}

export function markRevealed(id: string): void {
  revealed.add(id);
}

export function resetRevealed(): void {
  revealed.clear();
}
