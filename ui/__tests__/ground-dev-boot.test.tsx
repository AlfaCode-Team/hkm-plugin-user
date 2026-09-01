import { describe, it, expect, beforeEach } from "vitest";
import { existsSync } from "node:fs";
import { resolve } from "node:path";
import page from "../__fixtures__/ground-dev-register.json";

/**
 * Does `yarn dev` actually OPEN the page?
 *
 * The fixture is not hand-written: it is the exact `window.initialPage` object
 * that `hkm ground serve` emitted for GET /register, captured from the running
 * server. So this executes the real boot path — the generated dev entry, the
 * real page component, the real props — rather than a plausible imitation of
 * it. A component that renders here is one the browser renders too.
 *
 * The entry lives in the GENERATED workspace (ui/.ground/), which is gitignored
 * and only exists after `hkm ground dev`. When it is absent the test skips
 * rather than fails: a fresh clone has not generated it, and a red suite there
 * would be reporting the absence of a dev server, not a defect.
 */
describe("ground dev boots the plugin UI", () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    // The shipped Pageflow layout is in LEGACY mode: a bare root element plus
    // this global. The generated entry reads data-page first and falls back
    // here, so both layouts boot.
    (window as any).initialPage = page;
  });

  it("renders the component the server named, with the server's props", async () => {
    // The specifier is built at RUNTIME and marked @vite-ignore on purpose.
    // A literal import("../.ground/…") is resolved by Vite while TRANSFORMING
    // this file, so on a machine that has not run `hkm ground dev` the whole
    // test FILE fails to load — a try/catch around it never runs, and the
    // suite goes red for the absence of a dev workspace rather than a defect.
    const generated = "../.ground/src/surfaces/site/index.tsx";

    if (!existsSync(resolve(__dirname, generated))) {
      console.warn("skipped: run `hkm ground dev .` to generate the dev workspace");
      return;
    }

    const entry = await import(/* @vite-ignore */ generated);

    expect(entry).toBeTruthy();

    // createPageflowApp resolves the component and mounts asynchronously.
    await new Promise((resolve) => setTimeout(resolve, 250));

    const root = document.getElementById("app")!;

    expect(root.innerHTML.length).toBeGreaterThan(0);
    expect(root.querySelector("form")).not.toBeNull();
    expect(root.textContent).toMatch(/account|register|sign up/i);
  });
});
