/**
 * AI Coach constants.
 *
 * The prompts here are the *questions*, not the answers: every quick action
 * sends real text to the backend, which rebuilds the user's nutrition context
 * and asks the model. Nothing on this screen is scripted.
 */

export interface CoachQuickAction {
  /** Small, semantic, and never the only carrier of meaning. */
  emoji: string;
  label: string;
  /** What is actually sent. Fuller than the label, so the answer is specific. */
  prompt: string;
}

export const COACH_QUICK_ACTIONS: CoachQuickAction[] = [
  {
    emoji: "✨",
    label: "What should I eat next?",
    prompt: "What should I eat next?",
  },
  {
    emoji: "💪",
    label: "Help me hit my protein goal",
    prompt: "Help me hit my protein goal today. How much do I still need and where should it come from?",
  },
  {
    emoji: "🎯",
    label: "Improve today's balance",
    prompt: "How can I improve today's macro balance with what I have left?",
  },
  {
    emoji: "📊",
    label: "Analyze my week",
    prompt: "Analyse my last seven days. How consistent was I, and where were the gaps?",
  },
  {
    emoji: "🍽️",
    label: "Suggest a meal for my remaining macros",
    prompt: "Suggest a meal that fits my remaining calories and macros for today.",
  },
];

/**
 * Shown in sequence while a reply is in flight. Each one names something the
 * backend genuinely does — read today's totals, the recent meals, the goal —
 * rather than faking a progress bar over a request whose duration we cannot
 * know.
 */
export const COACH_THINKING_PHRASES = [
  "Checking today's nutrition…",
  "Reviewing your recent meals…",
  "Thinking about your goals…",
  "Looking at your last seven days…",
] as const;

/** Matches the server-side ceiling in CoachService::MAX_MESSAGE_LENGTH. */
export const COACH_MESSAGE_MAX_LENGTH = 1000;

/**
 * Split a reply into renderable blocks.
 *
 * Replies are plain text — the backend strips markdown headings and emphasis
 * before storing them — but a coach does sometimes answer with a short list,
 * and rendering "- Chicken" as a paragraph looks like a mistake. So blank
 * lines separate paragraphs, and runs of dashed lines become a list.
 */
export type CoachBlock =
  | { kind: "paragraph"; text: string }
  | { kind: "list"; items: string[] };

export function toCoachBlocks(content: string): CoachBlock[] {
  const blocks: CoachBlock[] = [];
  let paragraph: string[] = [];
  let list: string[] = [];

  const flushParagraph = () => {
    if (paragraph.length > 0) {
      blocks.push({ kind: "paragraph", text: paragraph.join("\n") });
      paragraph = [];
    }
  };

  const flushList = () => {
    if (list.length > 0) {
      blocks.push({ kind: "list", items: list });
      list = [];
    }
  };

  for (const rawLine of content.split("\n")) {
    const line = rawLine.trim();

    if (line === "") {
      flushList();
      flushParagraph();
      continue;
    }

    const bullet = /^[-•*]\s+(.*)$/.exec(line);

    if (bullet) {
      flushParagraph();
      list.push(bullet[1]);
      continue;
    }

    flushList();
    paragraph.push(line);
  }

  flushList();
  flushParagraph();

  return blocks;
}
