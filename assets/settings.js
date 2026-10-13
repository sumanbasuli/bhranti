(function () {
	'use strict';
	const mount = document.getElementById('bhranti-settings-app');
	const wp = window.wp || {};
	if (!mount || !wp.element || !wp.components || !wp.apiFetch) return;
	const { createElement: h, useEffect, useLayoutEffect, useRef, useState } = wp.element;
	const { Card, CardHeader, CardBody, TextControl, TextareaControl, ToggleControl, Button, Notice, Spinner, PanelBody } = wp.components;
	const __ = wp.i18n ? wp.i18n.__ : (value) => value;
	const settingsPage = window.bhrantiSettingsPage || {};
	const apiPath = settingsPage.apiPath || '/bhranti/v1/settings';
	const memoryApiPath = settingsPage.memoryApiPath || '/bhranti/v1/memory';
	const docLimit = 16000;
	const actionPermissions = [
		{ key: 'edit_content', label: __('Edit content', 'bhranti') },
		{ key: 'publish_content', label: __('Publish content', 'bhranti') },
		{ key: 'trash_content', label: __('Move content to Trash', 'bhranti') },
		{ key: 'manage_comments', label: __('Manage comments', 'bhranti') },
		{ key: 'manage_media', label: __('Manage media', 'bhranti') },
		{ key: 'manage_navigation', label: __('Manage navigation', 'bhranti') },
		{ key: 'manage_design', label: __('Manage templates and design', 'bhranti') },
		{ key: 'manage_site_settings', label: __('Change site settings', 'bhranti') },
		{ key: 'plugins_install', label: __('Install plugins', 'bhranti'), help: __('Install plugins from the WordPress.org directory.', 'bhranti') },
		{ key: 'plugins_activate', label: __('Activate or deactivate plugins', 'bhranti'), help: __('Undo restores activation status. Plugin lifecycle effects may remain.', 'bhranti') },
		{ key: 'plugins_delete', label: __('Delete plugins', 'bhranti'), help: __('Permanently uninstall inactive plugins only after approval. WordPress uninstall can remove plugin data; this action cannot be rolled back.', 'bhranti'), disabled: settingsPage.pluginDeletionAvailable !== true }
	];
	function validPermissions(permissions) {
		return permissions && !Array.isArray(permissions) && Object.keys(permissions).length === actionPermissions.length && actionPermissions.every(({ key }) => typeof permissions[key] === 'boolean');
	}
	async function readSettings(path = apiPath) {
		const controller = new AbortController(), timer = window.setTimeout(() => controller.abort(), 20000);
		try { return await wp.apiFetch({ path, signal: controller.signal }); }
		catch (error) { if (controller.signal.aborted) throw new Error(__('Loading took too long. Please try again.', 'bhranti')); throw error; }
		finally { window.clearTimeout(timer); }
	}
	function id(prefix) {
		const suffix = window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
		return prefix + '-' + suffix;
	}
	function validResult(result) {
		if (!result || !result.config || !Array.isArray(result.config.agents) || !validPermissions(result.config.permissions) || typeof result.revision !== 'string') throw new Error(__('WordPress returned invalid settings. Reload this page to try again.', 'bhranti'));
		if (!Object.prototype.hasOwnProperty.call(result.config, 'max_steps')) result = { ...result, config: { ...result.config, max_steps: 128 } };
		if (!Number.isInteger(result.config.max_steps) || result.config.max_steps < 12 || result.config.max_steps > 1000) throw new Error(__('WordPress returned an invalid task step limit. Reload this page to try again.', 'bhranti'));
		return result;
	}
	function validate(config) {
		if (!validPermissions(config.permissions)) return __('Action permissions must contain the supported settings as true or false values.', 'bhranti');
		if (!Number.isInteger(config.max_steps) || config.max_steps < 12 || config.max_steps > 1000) return __('Task step limit must be a whole number from 12 to 1,000.', 'bhranti');
		if (config.agents.length > 20 || !config.agents.some((agent) => agent.id === 'bhranti')) return __('Keep the built-in Bhranti agent and no more than 20 agents.', 'bhranti');
		const docs = [config.system_prompt, config.guardrails];
		for (const agent of config.agents) {
			if (!agent.name.trim()) return __('Give every agent a name before saving.', 'bhranti');
			if (Array.from(agent.name.trim()).length > 100) return __('Agent names must be 100 characters or fewer.', 'bhranti');
			if (agent.skills.length > 20) return __('Each agent can have up to 20 skills.', 'bhranti');
			docs.push(agent.agent_md);
			for (const skill of agent.skills) {
				if (!skill.name.trim()) return __('Give every skill a name before saving.', 'bhranti');
				if (Array.from(skill.name.trim()).length > 100) return __('Skill names must be 100 characters or fewer.', 'bhranti');
				docs.push(skill.skill_md);
			}
		}
		if (docs.some((doc) => typeof doc !== 'string' || Array.from(doc).length > docLimit)) return __('Each instruction document must be 16,000 characters or fewer.', 'bhranti');
		const total = Array.from(JSON.stringify(config)).length;
		if (total > 200000) return __('These settings are too large. Keep the total below 200,000 characters.', 'bhranti');
		return '';
	}
	function validMemory(result) {
		if (!result || typeof result.available !== 'boolean' || typeof result.revision !== 'string' || typeof result.message !== 'string' || !Array.isArray(result.preferences) || !Array.isArray(result.capabilities) || result.preferences.some((item) => !item || typeof item.id !== 'string' || typeof item.text !== 'string') || result.capabilities.some((item) => !item || typeof item.id !== 'string' || typeof item.summary !== 'string' || !Number.isFinite(item.observed) || !Number.isFinite(item.expires))) throw new Error(__('Memory could not be read. Refresh to try again.', 'bhranti'));
		return result;
	}
	function MemoryCard({ onBusy }) {
		const [memory, setMemory] = useState(null), [loading, setLoading] = useState(true), [resetting, setResetting] = useState(false), [confirm, setConfirm] = useState(false), [notice, setNotice] = useState(null), [needsRefresh, setNeedsRefresh] = useState(false);
		const alive = useRef(true), operation = useRef(false);
		useLayoutEffect(() => { onBusy(resetting); return () => onBusy(false); }, [resetting]);
		async function load() {
			if (operation.current) return;
			operation.current = true; setLoading(true); setNotice(null); setConfirm(false);
			try { const result = validMemory(await readSettings(memoryApiPath)); if (alive.current) { setMemory(result); setNeedsRefresh(false); } }
			catch (error) { if (alive.current) { setNeedsRefresh(true); setNotice({ status: 'error', text: __('Memory could not load. Refresh to try again.', 'bhranti') }); } }
			finally { operation.current = false; if (alive.current) setLoading(false); }
		}
		useEffect(() => { alive.current = true; load(); return () => { alive.current = false; }; }, []);
		async function reset() {
			if (!confirm || operation.current || needsRefresh || !memory || !memory.available) return;
			operation.current = true; setResetting(true); setNotice(null);
			try {
				const result = validMemory(await wp.apiFetch({ path: memoryApiPath + '/reset', method: 'POST', data: { revision: memory.revision } }));
				if (alive.current) { setMemory(result); setConfirm(false); setNotice({ status: 'success', text: __('Your memory was reset.', 'bhranti') }); }
			} catch (error) {
				if (alive.current) { setConfirm(false); setNeedsRefresh(true); setNotice({ status: 'error', text: error.data && error.data.status === 409 ? __('Your memory changed. Refresh and review it before resetting.', 'bhranti') : __('The reset could not be confirmed. Refresh to check your memory before trying again.', 'bhranti') }); }
			} finally { operation.current = false; if (alive.current) setResetting(false); }
		}
		const hasEntries = memory && (memory.preferences.length || memory.capabilities.length || memory.message), disabled = loading || resetting;
		return h(Card, { className: 'bhranti-settings-card' }, h(CardHeader, null, h('h2', null, __('Memory', 'bhranti'))), h(CardBody, null,
			h('p', { className: 'bhranti-settings-memory-help' }, __('Private to you on this site. Relevant preferences and verified capabilities are included when you chat.', 'bhranti')),
			notice && h(Notice, { status: notice.status, isDismissible: true, onRemove: () => setNotice(null) }, h('p', null, notice.text)),
			memory && memory.available && memory.message && h(Notice, { status: 'warning', isDismissible: false }, h('p', null, memory.message)),
			loading ? h('div', { className: 'bhranti-settings-loading', role: 'status' }, h(Spinner), __('Loading memory…', 'bhranti')) : memory && !memory.available ? h('p', { role: 'status' }, memory.message || __('Memory is currently unavailable. You can still chat with Bhranti.', 'bhranti')) : memory && h('div', null,
				h('h3', null, __('Saved preferences', 'bhranti')), memory.preferences.length ? h('ul', { className: 'bhranti-settings-memory-list' }, memory.preferences.map((item) => h('li', { key: item.id }, item.text))) : h('p', null, __('No saved preferences yet.', 'bhranti')),
				h(PanelBody, { title: __('Verified capabilities', 'bhranti'), initialOpen: false }, memory.capabilities.length ? h('ul', { className: 'bhranti-settings-memory-list' }, memory.capabilities.map((item) => h('li', { key: item.id }, item.summary, h('span', { className: 'bhranti-settings-memory-time' }, item.expires <= Date.now() / 1000 ? __('Expired — checked again when needed.', 'bhranti') : __('Recently verified; checked again when needed.', 'bhranti'))))) : h('p', null, __('No verified capabilities saved yet.', 'bhranti')))),
			confirm && h(Notice, { status: 'warning', isDismissible: false }, h('p', null, __('Reset your memory? This removes your saved preferences and verified capabilities for this site. Your chats and site content stay in place.', 'bhranti')), h('div', { className: 'bhranti-settings-actions' }, h(Button, { variant: 'secondary', isDestructive: true, onClick: reset, disabled, isBusy: resetting }, resetting ? __('Resetting…', 'bhranti') : __('Reset my memory', 'bhranti')), h(Button, { variant: 'tertiary', onClick: () => setConfirm(false), disabled }, __('Keep memory', 'bhranti')))),
			h('div', { className: 'bhranti-settings-actions' }, h(Button, { variant: 'secondary', onClick: load, disabled }, __('Refresh memory', 'bhranti')), !confirm && h(Button, { variant: 'tertiary', isDestructive: true, disabled: disabled || needsRefresh || !memory || !memory.available || !hasEntries, onClick: () => setConfirm(true) }, __('Reset memory', 'bhranti')))));
	}
	function Settings() {
		const [config, setConfig] = useState(null), [revision, setRevision] = useState('initial'), [baseline, setBaseline] = useState('');
		const [loading, setLoading] = useState(true), [saving, setSaving] = useState(false), [notice, setNotice] = useState(null), [removal, setRemoval] = useState(null), [reloadConfirm, setReloadConfirm] = useState(false);
		const [memoryBusy, setMemoryBusy] = useState(false);
		const dirty = config && JSON.stringify(config) !== baseline;
		const current = useRef(null), sync = useRef({ pending: false, generation: 0, running: false, alive: true });
		current.current = { config, dirty, saving, loading };
		useLayoutEffect(() => {
			mount.dataset.bhrantiDirty = String(!!dirty); mount.dataset.bhrantiBusy = String(loading || saving || memoryBusy || !config);
			window.dispatchEvent(new CustomEvent('bhranti:settings-state'));
		}, [!!dirty, loading, saving, memoryBusy, !!config]);
		async function refreshSaved() {
			const before = current.current;
			if (!sync.current.alive || !sync.current.pending || sync.current.running || before.dirty || before.loading || before.saving) return;
			const generation = sync.current.generation;
			sync.current.running = true; let retry = false;
			try {
				const result = validResult(await readSettings()), latest = current.current;
				if (!sync.current.alive) return;
				if (latest.config !== before.config || latest.dirty || latest.loading || latest.saving) { retry = !latest.dirty && !latest.loading && !latest.saving; return; }
				if (generation !== sync.current.generation) { retry = true; return; }
				sync.current.pending = false; setConfig(result.config); setRevision(result.revision); setBaseline(JSON.stringify(result.config));
			} catch (error) {
				if (sync.current.alive) setNotice({ status: 'warning', text: __('Bhranti saved a settings change. Reload saved settings to see it here.', 'bhranti'), conflict: true });
			} finally { sync.current.running = false; if (retry) refreshSaved(); }
		}
		useEffect(() => {
			sync.current.alive = true;
			const changed = () => { sync.current.pending = true; sync.current.generation++; refreshSaved(); };
			window.addEventListener('bhranti:settings-changed', changed);
			return () => { sync.current.alive = false; window.removeEventListener('bhranti:settings-changed', changed); };
		}, []);
		useEffect(() => { refreshSaved(); }, [!!dirty, saving, loading]);
		async function load() {
			const generation = sync.current.generation;
			setLoading(true); setNotice(null); setReloadConfirm(false);
			try { const result = validResult(await readSettings()); sync.current.pending = generation !== sync.current.generation; setConfig(result.config); setRevision(result.revision); setBaseline(JSON.stringify(result.config)); setRemoval(null); }
			catch (error) { setNotice({ status: 'error', text: error.message || __('Settings could not load. Please try again.', 'bhranti'), retry: true }); }
			finally { setLoading(false); }
		}
		useEffect(() => { load(); }, []);
		async function save() {
			if (saving || loading) return;
			const error = validate(config); if (error) { setNotice({ status: 'error', text: error }); return; }
			setSaving(true); setNotice(null);
			try {
				const result = validResult(await wp.apiFetch({ path: apiPath, method: 'POST', data: { config, revision } }));
				setConfig(result.config); setRevision(result.revision); setBaseline(JSON.stringify(result.config));
				setNotice({ status: 'success', text: __('Settings saved.', 'bhranti') });
				window.dispatchEvent(new CustomEvent('bhranti:settings-saved'));
			} catch (failure) { setNotice({ status: 'error', text: failure.message || __('Settings could not save. Your edits are still here.', 'bhranti'), conflict: failure.data && failure.data.status === 409 }); }
			finally { setSaving(false); }
		}
		function changeAgent(agentId, fields) { setConfig((current) => ({ ...current, agents: current.agents.map((agent) => agent.id === agentId ? { ...agent, ...fields } : agent) })); }
		function changeSkill(agent, skillId, fields) { changeAgent(agent.id, { skills: agent.skills.map((skill) => skill.id === skillId ? { ...skill, ...fields } : skill) }); }
		function remove(target) {
			if (target.kind === 'agent') setConfig((current) => ({ ...current, agents: current.agents.filter((agent) => agent.id !== target.agentId || agent.id === 'bhranti') }));
			else setConfig((current) => ({ ...current, agents: current.agents.map((agent) => agent.id === target.agentId ? { ...agent, skills: agent.skills.filter((skill) => skill.id !== target.skillId) } : agent) }));
			setRemoval(null);
		}
		function askRemoval(target, hasDocs) { if (hasDocs) setRemoval(target); else remove(target); }
		function confirmation(target) {
			if (!removal || removal.kind !== target.kind || removal.agentId !== target.agentId || removal.skillId !== target.skillId) return null;
			return h(Notice, { status: 'warning', isDismissible: false, className: 'bhranti-settings-confirm' },
				h('p', null, target.kind === 'agent' ? __('Remove this agent and its skills? The instruction documents in this card will be removed when you save.', 'bhranti') : __('Remove this skill? Its instruction document will be removed when you save.', 'bhranti')),
				h('div', { className: 'bhranti-settings-actions' }, h(Button, { variant: 'secondary', isDestructive: true, onClick: () => remove(target), disabled: saving }, __('Remove', 'bhranti')), h(Button, { variant: 'tertiary', onClick: () => setRemoval(null), disabled: saving }, __('Keep', 'bhranti'))));
		}
		function skillCard(agent, skill) {
			const target = { kind: 'skill', agentId: agent.id, skillId: skill.id };
			return h(PanelBody, { key: skill.id, title: skill.name || __('New skill', 'bhranti'), initialOpen: !skill.name && !skill.skill_md, className: 'bhranti-settings-skill' },
				h(TextControl, { label: __('Skill name', 'bhranti'), value: skill.name, onChange: (name) => changeSkill(agent, skill.id, { name }), disabled: saving }),
				h(TextareaControl, { label: __('Skill instructions (skill.md)', 'bhranti'), value: skill.skill_md, rows: 8, help: __('Paste the skill’s Markdown instructions. Stored as text; files are not executed.', 'bhranti'), onChange: (skill_md) => changeSkill(agent, skill.id, { skill_md }), disabled: saving }),
				h(Button, { variant: 'tertiary', isDestructive: true, onClick: () => askRemoval(target, !!skill.skill_md.trim()), disabled: saving }, __('Remove skill', 'bhranti')), confirmation(target));
		}
		function agentCard(agent) {
			const builtin = agent.id === 'bhranti', target = { kind: 'agent', agentId: agent.id };
			return h(Card, { key: agent.id, className: 'bhranti-settings-card' },
				h(CardHeader, null, h('h2', null, agent.name || __('New agent', 'bhranti')), builtin ? h('span', { className: 'bhranti-settings-builtin' }, __('Built in', 'bhranti')) : h(Button, { variant: 'tertiary', isDestructive: true, size: 'small', onClick: () => askRemoval(target, !!agent.agent_md.trim() || agent.skills.some((skill) => !!skill.skill_md.trim())), disabled: saving }, __('Remove agent', 'bhranti'))),
				h(CardBody, null, confirmation(target), h(PanelBody, { title: __('Instructions and skills', 'bhranti'), initialOpen: !agent.agent_md && !agent.skills.length },
					h(TextControl, { label: __('Agent name', 'bhranti'), value: agent.name, onChange: (name) => changeAgent(agent.id, { name }), disabled: saving }),
					h(TextareaControl, { label: __('Agent instructions (agent.md)', 'bhranti'), value: agent.agent_md, rows: 8, help: __('Describe this agent’s role and how it should work.', 'bhranti'), onChange: (agent_md) => changeAgent(agent.id, { agent_md }), disabled: saving }),
					h('h3', { className: 'bhranti-settings-skills-title' }, __('Skills', 'bhranti')),
					h('p', null, __('Built-in WordPress design and WCAG 2.2 AA guidance is included automatically. Your skills add to it.', 'bhranti')), agent.skills.map((skill) => skillCard(agent, skill)),
					h(Button, { variant: 'secondary', disabled: saving || agent.skills.length >= 20, onClick: () => changeAgent(agent.id, { skills: agent.skills.concat({ id: id('skill'), name: '', skill_md: '' }) }) }, __('Add skill', 'bhranti')))));
		}
		const heading = h('header', { className: 'bhranti-settings-header' }, h('h1', null, __('Bhranti Settings', 'bhranti')), h('p', null, __('Manage shared instructions, agents, and skills for your WordPress site.', 'bhranti')));
		const noticeNode = notice && h(Notice, { status: notice.status, onRemove: () => setNotice(null), isDismissible: true }, h('p', null, notice.text), notice.retry && h(Button, { variant: 'secondary', onClick: load, disabled: loading }, __('Try again', 'bhranti')), notice.conflict && h(Button, { variant: 'secondary', onClick: () => dirty ? setReloadConfirm(true) : load(), disabled: loading || saving }, __('Reload saved settings', 'bhranti')));
		if (loading) return h('div', { className: 'bhranti-settings' }, heading, h('div', { className: 'bhranti-settings-content' }, noticeNode, h('div', { className: 'bhranti-settings-loading', role: 'status' }, h(Spinner), __('Loading settings…', 'bhranti'))));
		if (!config) return h('div', { className: 'bhranti-settings' }, heading, h('div', { className: 'bhranti-settings-content' }, noticeNode));
		return h('div', { className: 'bhranti-settings' }, heading, h('div', { className: 'bhranti-settings-content' }, noticeNode,
			reloadConfirm && h(Notice, { status: 'warning', isDismissible: false }, h('p', null, __('Reloading replaces your unsaved edits with the saved settings.', 'bhranti')), h('div', { className: 'bhranti-settings-actions' }, h(Button, { variant: 'secondary', onClick: load }, __('Reload', 'bhranti')), h(Button, { variant: 'tertiary', onClick: () => setReloadConfirm(false) }, __('Keep editing', 'bhranti')))),
			h(Card, { className: 'bhranti-settings-card' }, h(CardHeader, null, h('h2', null, __('Action permissions', 'bhranti'))), h(CardBody, null,
				h('p', { className: 'bhranti-settings-permissions-help' }, __('When enabled, normal operations can proceed. WordPress permissions still apply, and deletion requires confirmation.', 'bhranti')),
				h(TextControl, { label: __('Task step limit', 'bhranti'), type: 'number', min: 12, max: 1000, step: 1, value: config.max_steps, help: __('Maximum AI planning steps for each new task, from 12 to 1,000. The default is 128; an active task keeps the limit it started with.', 'bhranti'), disabled: saving, onChange: (value) => setConfig((current) => ({ ...current, max_steps: value === '' ? '' : Number(value) })) }),
				actionPermissions.map(({ key, label, help, disabled }) => h(ToggleControl, { key, label, help, checked: config.permissions[key], disabled: saving || disabled, onChange: (checked) => setConfig((current) => ({ ...current, permissions: { ...current.permissions, [key]: checked } })) })))),
			h(Card, { className: 'bhranti-settings-card' }, h(CardHeader, null, h('h2', null, __('Global instructions', 'bhranti'))), h(CardBody, null,
				h(TextareaControl, { label: __('System prompt', 'bhranti'), value: config.system_prompt, rows: 6, help: __('Additional instructions applied to every agent.', 'bhranti'), onChange: (system_prompt) => setConfig({ ...config, system_prompt }), disabled: saving }),
				h(TextareaControl, { label: __('Guardrails', 'bhranti'), value: config.guardrails, rows: 6, help: __('Rules every agent should follow. WordPress permissions and built-in confirmations still apply.', 'bhranti'), onChange: (guardrails) => setConfig({ ...config, guardrails }), disabled: saving }))),
			h(MemoryCard, { onBusy: setMemoryBusy }),
			h('div', { className: 'bhranti-settings-section-heading' }, h('h2', null, __('Agents', 'bhranti')), h(Button, { variant: 'secondary', disabled: saving || config.agents.length >= 20, onClick: () => setConfig({ ...config, agents: config.agents.concat({ id: id('agent'), name: '', agent_md: '', skills: [] }) }) }, __('Add agent', 'bhranti'))),
			config.agents.map(agentCard), h('div', { className: 'bhranti-settings-save' }, h(Button, { variant: 'primary', onClick: save, disabled: saving || !dirty, isBusy: saving }, saving ? __('Saving…', 'bhranti') : __('Save settings', 'bhranti')), dirty && h('span', null, __('Unsaved changes', 'bhranti')))));
	}
	if (wp.element.createRoot) wp.element.createRoot(mount).render(h(Settings));
	else wp.element.render(h(Settings), mount);
})();
