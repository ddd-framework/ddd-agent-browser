'use client';

import { type PageLinkComponent, useLinkComponent } from '@mgamadeus/ddd-admin-ui/primitives';

export interface BrowserConversationLinkProps {
  conversationId: number;
  /** The host's link of the conversation; `null` = the id as text. */
  href: string | null;
  /** The host's router link; default the one of `AdminUiProvider.linkComponent`, else a plain `<a>` (full page load). */
  linkComponent?: PageLinkComponent;
}

/** `#<conversationId>` as the host's router link (no full page load inside the admin app), or as text without a link. */
export const BrowserConversationLink = ({ conversationId, href, linkComponent }: BrowserConversationLinkProps) => {
  const Link = useLinkComponent(linkComponent);
  if (!href) return <span className="font-mono text-xs">#{conversationId}</span>;
  return (
    <Link href={href} className="font-mono text-xs underline-offset-2 hover:underline">
      #{conversationId}
    </Link>
  );
};
