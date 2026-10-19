/**
 * Feedback inbox types.
 *
 * The shapes the REST layer actually returns, so a change in
 * `Licensio_KB_Feedback_REST_Controller::prepare_row()` is a compile error
 * here rather than an undefined at runtime.
 *
 * @since 1.0.0
 */

export type FeedbackStatus = 'new' | 'reviewed' | 'actioned';

export interface FeedbackItem {
	id: number;
	articleId: number;
	articleName: string;
	editUrl: string;
	viewUrl: string;
	vote: number;
	comment: string;
	status: FeedbackStatus;
	createdAt: string;
	userId: number;
	userName: string;
}

export type FeedbackCounts = Record< string, number >;

export interface FeedbackPage {
	items: FeedbackItem[];
	total: number;
	pages: number;
	page: number;
	perPage: number;
	statuses: FeedbackStatus[];
	counts: FeedbackCounts;
}

export interface InboxSettings {
	root: string;
	nonce: string;
	perPage: number;
	statuses: FeedbackStatus[];
	settingsUrl?: string;
}
