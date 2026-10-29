<?php

namespace CustomCRM\LocalTime;

use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Services\PermissionManager;
use WP_REST_Request;

/**
 * The "Deliver in each person's local time" panel in FluentCRM's campaign schedule step, and the
 * REST route it saves through.
 *
 * FluentCRM's admin app has no extension point for campaign fields, so the panel is attached to
 * the DOM: it finds the "Schedule the emails" radio button by its value and appends itself to
 * that block, re-attaching whenever the app re-renders. The script is printed inline because
 * FluentCRM's no-conflict mode removes other plugins' script files from its page.
 */
class LocalTimePanel {

	private const REST_NAMESPACE = 'customcrm/v1';

	/**
	 * Allowed window, in whole hours of the local day.
	 */
	private const EARLIEST_HOUR = 5;

	private const LATEST_HOUR = 22;

	/**
	 * Share of people kept on the normal schedule for comparison. Off unless the sender turns it on.
	 */
	private const DEFAULT_HOLDOUT = 0;

	private const MAX_HOLDOUT = 50;

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
		add_action( 'admin_footer', [ $this, 'printPanelScript' ] );
	}

	public function registerRoutes(): void {
		register_rest_route( self::REST_NAMESPACE, '/local-time/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'getState' ],
				'permission_callback' => [ $this, 'canManage' ],
				'args'                => [
					'date' => [ 'type' => 'string', 'required' => false ],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'saveState' ],
				'permission_callback' => [ $this, 'canManage' ],
				'args'                => [
					'enabled' => [ 'type' => 'boolean', 'required' => true ],
					'start'   => [ 'type' => 'integer', 'required' => true ],
					'end'     => [ 'type' => 'integer', 'required' => true ],
					'holdout' => [ 'type' => 'integer', 'required' => false ],
					'date'    => [ 'type' => 'string', 'required' => false ],
				],
			],
		] );
	}

	public function canManage(): bool {
		return PermissionManager::currentUserCan( 'fcrm_manage_emails' );
	}

	public function getState( WP_REST_Request $request ) {
		$campaign_id = (int) $request['id'];
		$campaign    = Campaign::withoutGlobalScope( 'type' )->find( $campaign_id );

		if ( ! $campaign ) {
			return new \WP_Error( 'not_found', 'Campaign not found.', [ 'status' => 404 ] );
		}

		$date = $this->pickedDate( $request );

		if ( is_wp_error( $date ) ) {
			return $date;
		}

		$plan  = LocalTimeSending::getPlan( $campaign_id );
		$state = [
			'editable' => 'draft' === $campaign->status,
			'plan'     => [
				'enabled' => (bool) $plan['enabled'],
				'start'   => (int) $plan['start'],
				'end'     => (int) $plan['end'],
				'holdout' => isset( $plan['holdout'] ) ? (int) $plan['holdout'] : self::DEFAULT_HOLDOUT,
				'date'    => (string) $plan['date'],
			],
			'result'   => (string) fluentcrm_get_campaign_meta( $campaign_id, LocalTimeSending::RESULT_META, true ),
		];

		if ( '' !== $date ) {
			$state['feasibility'] = LocalTimeSending::feasibility( $date, $plan );
		}

		return $state;
	}

	public function saveState( WP_REST_Request $request ) {
		$campaign_id = (int) $request['id'];
		$campaign    = Campaign::withoutGlobalScope( 'type' )->find( $campaign_id );

		if ( ! $campaign ) {
			return new \WP_Error( 'not_found', 'Campaign not found.', [ 'status' => 404 ] );
		}

		if ( 'draft' !== $campaign->status ) {
			return new \WP_Error( 'campaign_locked', 'This campaign is no longer a draft, so its local-time settings cannot change.', [ 'status' => 409 ] );
		}

		$start = (int) $request['start'];
		$end   = (int) $request['end'];

		$in_range = $start >= self::EARLIEST_HOUR * 60 && $end <= self::LATEST_HOUR * 60 && $end > $start;

		if ( ! $in_range || 0 !== $start % 60 || 0 !== $end % 60 ) {
			return new \WP_Error( 'invalid_window', 'Pick whole hours, with the end after the start.', [ 'status' => 400 ] );
		}

		$stored  = fluentcrm_get_campaign_meta( $campaign_id, LocalTimeSending::PLAN_META, true );
		$stored  = is_array( $stored ) ? $stored : [];
		$holdout = $request->has_param( 'holdout' )
			? (int) $request['holdout']
			: ( isset( $stored['holdout'] ) ? (int) $stored['holdout'] : self::DEFAULT_HOLDOUT );

		if ( $holdout < 0 || $holdout > self::MAX_HOLDOUT ) {
			return new \WP_Error( 'invalid_holdout', 'The comparison share must be a whole number from 0 to ' . self::MAX_HOLDOUT . '.', [ 'status' => 400 ] );
		}

		$date = $this->pickedDate( $request );

		if ( is_wp_error( $date ) ) {
			return $date;
		}

		// Keys this panel does not own (date, anchor, generation) stay as stored.
		LocalTimeSending::savePlan( $campaign_id, array_merge( $stored, [
			'enabled' => (bool) $request['enabled'],
			'start'   => $start,
			'end'     => $end,
			'holdout' => $holdout,
		] ) );

		return $this->getState( $request );
	}

	/**
	 * The delivery date the sender picked, as Y-m-d. Any time part is ignored.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return string|\WP_Error The date, '' when none was sent, or an error for a malformed one.
	 */
	private function pickedDate( WP_REST_Request $request ) {
		$raw = $request->get_param( 'date' );

		if ( null === $raw || '' === $raw ) {
			return '';
		}

		$matched = is_string( $raw ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ T].*)?$/', $raw, $parts );
		$valid   = $matched && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );

		if ( ! $valid ) {
			return new \WP_Error( 'invalid_date', 'The date must look like 2026-12-31.', [ 'status' => 400 ] );
		}

		return $parts[1] . '-' . $parts[2] . '-' . $parts[3];
	}

	public function printPanelScript(): void {
		$is_crm_page = isset( $_GET['page'] ) && 'fluentcrm-admin' === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $is_crm_page || ! $this->canManage() ) {
			return;
		}

		$config = [
			'root'           => esc_url_raw( rest_url( self::REST_NAMESPACE . '/local-time/' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'earliestHour'   => self::EARLIEST_HOUR,
			'latestHour'     => self::LATEST_HOUR,
			'defaultHoldout' => self::DEFAULT_HOLDOUT,
			'maxHoldout'     => self::MAX_HOLDOUT,
		];
		?>
		<style>
			.gk-local-time { margin-top: 16px; padding: 14px 16px; border: 1px solid #dcdfe6; border-radius: 6px; background: #f8fafc; max-width: 640px; }
			.gk-local-time [hidden] { display: none !important; }
			.gk-local-time label { font-weight: 600; cursor: pointer; }
			.gk-local-time__window, .gk-local-time__holdout { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
			.gk-local-time__window select { min-width: 96px; }
			.gk-local-time__holdout input { width: 64px; }
			.gk-local-time__help, .gk-local-time__summary { margin: 10px 0 0; color: #475569; font-size: 13px; line-height: 1.5; }
			.gk-local-time__status { margin: 8px 0 0; font-size: 13px; color: #475569; }
			.gk-local-time__status.is-error, .gk-local-time__notice { color: #b91c1c; }
			.gk-local-time__notice { margin: 8px 0 0; font-size: 13px; font-weight: 600; }
		</style>
		<script>
		(function (config) {
			var panel = null;
			var panelCampaign = null;
			var panelApi = null;

			function campaignId() {
				var match = window.location.hash.match(/^#\/email\/campaigns\/(\d+)(?:\?|$)/);
				return match ? parseInt(match[1], 10) : null;
			}

			function hourLabel(minutes) {
				var hour = Math.floor(minutes / 60);
				var suffix = hour < 12 ? 'AM' : 'PM';
				var shown = hour % 12 === 0 ? 12 : hour % 12;
				return shown + ':00 ' + suffix;
			}

			function fillHours(select, from, to, selected) {
				select.textContent = '';
				for (var hour = from; hour <= to; hour++) {
					var option = document.createElement('option');
					option.value = String(hour * 60);
					option.textContent = hourLabel(hour * 60);
					option.selected = hour * 60 === selected;
					select.appendChild(option);
				}
			}

			function request(url, method, body) {
				return fetch(url, {
					method: method,
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
					body: body ? JSON.stringify(body) : undefined
				}).then(function (response) {
					return response.json().then(function (data) {
						if (!response.ok) {
							var error = new Error(data && data.message ? data.message : 'Request failed (' + response.status + ')');
							error.code = data && data.code ? data.code : '';
							throw error;
						}
						return data;
					});
				});
			}

			function clampHoldout(value) {
				var number = parseInt(value, 10);
				if (isNaN(number)) {
					return config.defaultHoldout;
				}
				return Math.min(Math.max(number, 0), config.maxHoldout);
			}

			// The "Schedule this campaign" button: the primary button in the footer of the card that
			// holds the send-type radios (only the review step has them), while "Schedule" is chosen.
			function scheduleButton(target) {
				var button = target && target.closest ? target.closest('button.el-button--primary') : null;
				var footer = button ? button.closest('.fcrm_base_card_footer') : null;
				var card = footer ? footer.parentElement : null;
				var radio = card ? card.querySelector('input[type="radio"][value="schedule"]') : null;
				return radio && radio.checked ? button : null;
			}

			function build(id) {
				var url = config.root + id;
				var state = {
					editable: true,
					loaded: false,
					saving: false,
					dirty: false,
					failed: '',
					serverEnabled: false,
					stamp: 0,
					feas: { date: '', status: 'none', ok: true, message: '' }
				};

				var root = document.createElement('div');
				root.className = 'gk-local-time';
				root.setAttribute('data-campaign', String(id));

				var toggleLabel = document.createElement('label');
				var toggle = document.createElement('input');
				toggle.type = 'checkbox';
				toggle.disabled = true;
				toggleLabel.appendChild(toggle);
				toggleLabel.appendChild(document.createTextNode(" Deliver in each person's local time"));
				root.appendChild(toggleLabel);

				var controls = document.createElement('div');
				root.appendChild(controls);

				var windowRow = document.createElement('div');
				windowRow.className = 'gk-local-time__window';
				var start = document.createElement('select');
				var end = document.createElement('select');
				start.setAttribute('aria-label', 'Earliest local delivery time');
				end.setAttribute('aria-label', 'Latest local delivery time');
				windowRow.appendChild(document.createTextNode('Between'));
				windowRow.appendChild(start);
				windowRow.appendChild(document.createTextNode('and'));
				windowRow.appendChild(end);
				windowRow.appendChild(document.createTextNode('in their time zone, on the date picked above.'));
				controls.appendChild(windowRow);

				var holdoutRow = document.createElement('div');
				holdoutRow.className = 'gk-local-time__holdout';
				var holdout = document.createElement('input');
				holdout.type = 'number';
				holdout.min = '0';
				holdout.max = String(config.maxHoldout);
				holdout.step = '1';
				holdout.setAttribute('aria-label', 'Percent kept on the normal schedule');
				holdoutRow.appendChild(document.createTextNode('Keep'));
				holdoutRow.appendChild(holdout);
				holdoutRow.appendChild(document.createTextNode('% on the normal schedule for comparison.'));
				controls.appendChild(holdoutRow);

				var help = document.createElement('p');
				help.className = 'gk-local-time__help';
				controls.appendChild(help);

				var summary = document.createElement('p');
				summary.className = 'gk-local-time__summary';
				root.appendChild(summary);

				var notice = document.createElement('p');
				notice.className = 'gk-local-time__notice';
				notice.setAttribute('role', 'alert');
				notice.hidden = true;
				root.appendChild(notice);

				var status = document.createElement('p');
				status.className = 'gk-local-time__status';
				status.setAttribute('role', 'status');
				root.appendChild(status);

				var lastResult = '';
				var lastPlan = null;

				function pickedDate() {
					var block = root.parentElement;
					var input = block ? block.querySelector('.el-date-editor input') : null;
					var match = input ? /^(\d{4}-\d{2}-\d{2})/.exec(input.value) : null;
					return match ? match[1] : '';
				}

				function readControls() {
					var startValue = parseInt(start.value, 10);
					var endValue = Math.max(parseInt(end.value, 10), startValue + 60);
					return { enabled: toggle.checked, start: startValue, end: endValue, holdout: clampHoldout(holdout.value) };
				}

				function syncEnd(selected) {
					var startHour = Math.floor(parseInt(start.value, 10) / 60);
					fillHours(end, startHour + 1, config.latestHour, Math.max(selected, (startHour + 1) * 60));
				}

				function fillControls(plan) {
					toggle.checked = !!plan.enabled;
					fillHours(start, config.earliestHour, config.latestHour - 1, plan.start);
					syncEnd(plan.end);
					holdout.value = String(plan.holdout);
				}

				function describe(plan) {
					if (!plan.enabled) {
						return 'Local-time delivery is off for this campaign. It sends at the time picked above.';
					}
					var text = 'Local-time delivery is on. Each person gets this email between ' + hourLabel(plan.start) + ' and ' + hourLabel(plan.end)
						+ ' in their time zone, on the date picked above.';
					if (plan.holdout > 0) {
						text += ' ' + plan.holdout + '% of people get it at ' + hourLabel(plan.start) + ' in the site time zone instead, for comparison.';
					}
					return text;
				}

				function blockReason() {
					if (!state.editable || !state.loaded) {
						return '';
					}
					var on = toggle.checked || state.serverEnabled;
					if (!on) {
						return '';
					}
					if (state.failed) {
						return 'Your local-time setting did not save, so scheduling is paused. ' + state.failed;
					}
					if (state.saving || state.dirty) {
						return 'Your local-time setting is still saving. Try again in a moment.';
					}
					if (!toggle.checked) {
						return '';
					}
					var date = pickedDate();
					if (!date) {
						return '';
					}
					if (state.feas.date !== date || state.feas.status === 'checking') {
						return 'Checking the delivery date. Try again in a moment.';
					}
					if (state.feas.status === 'error') {
						return 'Could not check the delivery date: ' + state.feas.message;
					}
					return state.feas.ok ? '' : state.feas.message;
				}

				function refresh() {
					var editable = state.editable;
					controls.hidden = !editable || !toggle.checked;
					toggle.disabled = !state.loaded || !editable;
					start.disabled = end.disabled = holdout.disabled = !state.loaded;
					summary.hidden = editable || !lastPlan;
					if (lastPlan && !editable) {
						summary.textContent = 'These settings are locked because the campaign is no longer a draft. ' + describe(lastPlan);
					}
					help.textContent = 'The time picked above is ignored; only its date counts. Sending takes about a day, as the window reaches each time zone. '
						+ 'After you schedule, FluentCRM lists an earlier start time: when ' + hourLabel(parseInt(start.value, 10) || 0) + ' on that date first arrives anywhere (UTC+14).';

					var date = pickedDate();
					var infeasible = editable && toggle.checked && state.feas.status === 'done' && state.feas.date === date && !state.feas.ok;
					notice.textContent = infeasible ? state.feas.message : '';
					notice.hidden = !infeasible;

					if (state.failed) {
						status.className = 'gk-local-time__status is-error';
						status.textContent = 'Not saved: ' + state.failed;
					} else if (state.saving || state.dirty) {
						status.className = 'gk-local-time__status';
						status.textContent = 'Saving…';
					}
				}

				function showNotice(text) {
					notice.textContent = text;
					notice.hidden = false;
				}

				function applyServer(data, fill) {
					state.editable = !!data.editable;
					state.serverEnabled = !!data.plan.enabled;
					lastPlan = data.plan;
					lastResult = data.result || '';
					if (fill) {
						fillControls(data.plan);
					}
				}

				function applyFeasibility(data, date) {
					state.feas = data.feasibility
						? { date: date, status: 'done', ok: !!data.feasibility.ok, message: String(data.feasibility.message || '') }
						: { date: date, status: 'none', ok: true, message: '' };
				}

				function ensureFeasibility() {
					if (!state.loaded || !state.editable || state.saving || state.dirty) {
						return;
					}
					var date = pickedDate();
					if (!date) {
						state.feas = { date: '', status: 'none', ok: true, message: '' };
						return;
					}
					if (state.feas.date === date) {
						return;
					}
					state.feas = { date: date, status: 'checking', ok: true, message: '' };
					var stamp = ++state.stamp;
					request(url + '?date=' + encodeURIComponent(date), 'GET')
						.then(function (data) {
							if (stamp !== state.stamp) {
								return;
							}
							applyFeasibility(data, date);
							refresh();
						})
						.catch(function (error) {
							if (stamp !== state.stamp) {
								return;
							}
							state.feas = { date: date, status: 'error', ok: false, message: error.message };
							refresh();
						});
				}

				// One save at a time. A change made while a save runs is sent afterwards, as the latest state.
				function pump() {
					if (state.saving || !state.dirty) {
						return;
					}
					state.saving = true;
					state.dirty = false;
					var body = readControls();
					var date = pickedDate();
					if (date) {
						body.date = date;
					}
					var stamp = ++state.stamp;
					refresh();

					request(url, 'POST', body)
						.then(function (data) {
							if (stamp !== state.stamp) {
								return;
							}
							state.failed = '';
							applyServer(data, false);
							applyFeasibility(data, date);
							status.className = 'gk-local-time__status';
							status.textContent = body.enabled ? 'Saved. Local-time delivery is on for this campaign.' : 'Saved. This campaign sends at the time picked above.';
						})
						.catch(function (error) {
							if (stamp !== state.stamp) {
								return;
							}
							state.failed = error.message;
							if (error.code === 'campaign_locked') {
								load();
							}
						})
						.then(function () {
							state.saving = false;
							if (state.dirty) {
								pump();
								return;
							}
							ensureFeasibility();
							refresh();
						});
				}

				function changed() {
					holdout.value = String(clampHoldout(holdout.value));
					state.dirty = true;
					state.feas = { date: '', status: 'none', ok: true, message: '' };
					refresh();
					pump();
				}

				function load() {
					var date = pickedDate();
					var stamp = ++state.stamp;
					status.className = 'gk-local-time__status';
					status.textContent = 'Loading…';
					request(url + (date ? '?date=' + encodeURIComponent(date) : ''), 'GET')
						.then(function (data) {
							if (stamp !== state.stamp) {
								return;
							}
							state.loaded = true;
							applyServer(data, !state.saving && !state.dirty);
							applyFeasibility(data, date);
							status.textContent = lastResult ? 'Last result: ' + lastResult : '';
							refresh();
						})
						.catch(function (error) {
							if (stamp !== state.stamp) {
								return;
							}
							status.className = 'gk-local-time__status is-error';
							status.textContent = 'Could not load the local-time setting: ' + error.message;
						});
				}

				toggle.addEventListener('change', changed);
				start.addEventListener('change', function () {
					syncEnd(parseInt(end.value, 10));
					changed();
				});
				end.addEventListener('change', changed);
				holdout.addEventListener('change', changed);

				panelApi = {
					blockReason: blockReason,
					showNotice: showNotice,
					tick: function () {
						ensureFeasibility();
						refresh();
					},
					retry: function () {
						if (state.feas.status === 'error') {
							state.feas.date = '';
							ensureFeasibility();
						}
					}
				};

				refresh();
				load();

				return root;
			}

			function attach() {
				var id = campaignId();
				var scheduleRadio = id ? document.querySelector('input[type="radio"][value="schedule"]') : null;
				var group = scheduleRadio ? scheduleRadio.closest('.el-radio-group') : null;
				var block = group ? group.parentElement : null;

				if (!block) {
					return;
				}

				if (!panel || panelCampaign !== id) {
					panel = build(id);
					panelCampaign = id;
				}

				if (panel.parentElement !== block) {
					block.appendChild(panel);
				}

				panel.hidden = !scheduleRadio.checked;
			}

			// Stop FluentCRM's "Schedule this campaign" while the local-time setting is unsaved, failed,
			// or points at a date that cannot be met. With local time off, nothing is touched.
			document.addEventListener('click', function (event) {
				var live = panel && panelApi && panel.isConnected;
				if (!live || !scheduleButton(event.target)) {
					return;
				}
				var reason = panelApi.blockReason();
				if (!reason) {
					return;
				}
				event.preventDefault();
				event.stopImmediatePropagation();
				panelApi.showNotice(reason);
				panelApi.retry();
			}, true);

			var queued = false;
			new MutationObserver(function () {
				if (queued) {
					return;
				}
				queued = true;
				window.requestAnimationFrame(function () {
					queued = false;
					attach();
				});
			}).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });

			// The date picker changes an input's value property, which no observer sees.
			window.setInterval(function () {
				if (panel && panelApi && panel.isConnected) {
					panelApi.tick();
				}
			}, 500);

			document.addEventListener('change', attach, true);
			window.addEventListener('hashchange', attach);
			attach();
		})(<?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ); ?>);
		</script>
		<?php
	}
}
