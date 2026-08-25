import { ref, reactive, computed, watch } from 'vue';
import { state, loadTags } from '@/core/store.js';
import { post, put, del } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { useFuzzy } from '@/core/fuzzy.js';
import { formatDate, plural } from '@/core/format.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import EmptyState from '@/components/EmptyState.js';
import ViewHeader from '@/components/ViewHeader.js';

export const TagsView = {
  name: 'TagsView',
  components: { AppIcon, AppModal, EmptyState, ViewHeader },
  setup() {
    const search = ref('');
    const sortKey = ref('name');
    const sortDir = ref('asc');
    const perPage = ref(100);
    const page = ref(1);
    const busy = ref(false);

    const draft = reactive({ name: '', value: '' });
    const editingId = ref(null);
    const edit = reactive({ name: '', value: '' });
    const confirmDelete = ref(null);

    const found = useFuzzy(computed(() => state.tags), search, { keys: ['name', 'value'] });

    const sorted = computed(() => {
      const direction = sortDir.value === 'asc' ? 1 : -1;
      const key = sortKey.value;
      return [...found.value].sort((a, b) => {
        const x = a[key] ?? '';
        const y = b[key] ?? '';
        if (typeof x === 'number' || typeof y === 'number') return (Number(x) - Number(y)) * direction;
        return String(x).localeCompare(String(y), undefined, { sensitivity: 'base' }) * direction;
      });
    });

    const pageCount = computed(() =>
      perPage.value === 0 ? 1 : Math.max(1, Math.ceil(sorted.value.length / perPage.value))
    );
    const visible = computed(() => {
      if (perPage.value === 0) return sorted.value;
      const start = (page.value - 1) * perPage.value;
      return sorted.value.slice(start, start + perPage.value);
    });

    watch([sorted, perPage], () => { if (page.value > pageCount.value) page.value = pageCount.value; });
    watch(search, () => { page.value = 1; });

    /** A caret in the header, so the current sort is visible and reversible. */
    const caret = (key) => (sortKey.value === key ? (sortDir.value === 'asc' ? ' ▲' : ' ▼') : '');

    const sortBy = (key) => {
      if (sortKey.value === key) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
      else { sortKey.value = key; sortDir.value = key === 'usage_count' ? 'desc' : 'asc'; }
    };

    const run = (action, label) => attempt(async () => {
      busy.value = true;
      try { return await action(); } finally { busy.value = false; }
    }, label);

    const create = () => run(async () => {
      if (!draft.name.trim()) { toast.error('A tag needs a name.'); return; }
      const data = await post('tags', { name: draft.name.trim(), value: draft.value.trim() });
      state.tags = data.tags ?? state.tags;
      draft.name = '';
      draft.value = '';
      toast.success('Tag created.');
    }, 'Create tag');

    const startEdit = (tag) => {
      editingId.value = tag.id;
      edit.name = tag.name;
      edit.value = tag.value || '';
    };

    const save = (tag) => run(async () => {
      if (!edit.name.trim()) { toast.error('A tag needs a name.'); return; }
      const data = await put(`tags/${tag.id}`, { name: edit.name.trim(), value: edit.value.trim() });
      state.tags = data.tags ?? state.tags;
      editingId.value = null;
      toast.success('Tag saved.');
    }, 'Save tag');

    const remove = (tag) => run(async () => {
      const data = await del(`tags/${tag.id}`);
      state.tags = data.tags ?? state.tags;
      confirmDelete.value = null;
      if (editingId.value === tag.id) editingId.value = null;
      toast.success('Tag deleted.');
    }, 'Delete tag');

    return {
      state, search, perPage, page, pageCount, visible, sorted,
      draft, editingId, edit, busy, confirmDelete,
      create, startEdit, save, remove, sortBy, caret, loadTags,
      formatDate, plural,
    };
  },
  template: `
    <view-header title="Tags" icon="tag">
      <template #actions>
        <span class="badge hide-sm">{{ plural(state.tags.length, 'tag') }}</span>
        <button class="btn btn--ghost btn--icon" title="Reload" @click="loadTags">
          <app-icon name="refresh" :size="15"/>
        </button>
      </template>
    </view-header>

    <div class="view-scroll">
      <div class="view-pad container-narrow">
        <div class="card mb-4">
          <div class="card__body">
            <p class="eyebrow mb-3">New tag</p>
            <div class="row wrap gap-3 items-end">
              <div class="form-row grow" style="min-width:200px">
                <label for="tag-name">Name</label>
                <input id="tag-name" v-model="draft.name" placeholder="JavaScript" @keydown.enter="create">
              </div>
              <div class="form-row grow" style="min-width:200px">
                <label for="tag-value">Value <span class="faint">(optional, pushed to BookStack)</span></label>
                <input id="tag-value" v-model="draft.value" placeholder="frontend" @keydown.enter="create">
              </div>
              <button class="btn btn--primary none" :disabled="busy || !draft.name.trim()" @click="create">
                <app-icon name="plus" :size="15"/> Add
              </button>
            </div>
          </div>
        </div>

        <div class="row wrap gap-3 mb-3" v-if="state.tags.length">
          <div class="grow" style="max-width:360px;position:relative">
            <app-icon name="search" :size="14"
                      style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-faint)"/>
            <input v-model="search" placeholder="Search name or value…" style="padding-left:30px" spellcheck="false">
          </div>
          <div class="row gap-2 push none">
            <span class="t-xs dim">Per page</span>
            <select v-model.number="perPage" style="width:auto">
              <option :value="25">25</option>
              <option :value="50">50</option>
              <option :value="100">100</option>
              <option :value="250">250</option>
              <option :value="0">All</option>
            </select>
          </div>
        </div>

        <div v-if="visible.length" class="card" style="overflow:hidden">
          <div class="scroll-x">
            <table class="table">
              <thead>
                <tr>
                  <th style="cursor:pointer" @click="sortBy('name')">Name{{ caret('name') }}</th>
                  <th style="cursor:pointer" @click="sortBy('value')">Value{{ caret('value') }}</th>
                  <th class="table__num" style="cursor:pointer;width:90px" @click="sortBy('usage_count')">Used{{ caret('usage_count') }}</th>
                  <th style="cursor:pointer;width:120px" @click="sortBy('updated_at')">Updated{{ caret('updated_at') }}</th>
                  <th style="width:140px"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="tag in visible" :key="tag.id">
                  <template v-if="editingId === tag.id">
                    <td><input v-model="edit.name" @keydown.enter="save(tag)" @keydown.esc="editingId = null"></td>
                    <td><input v-model="edit.value" @keydown.enter="save(tag)" @keydown.esc="editingId = null"></td>
                    <td class="table__num nums dim">{{ tag.usage_count }}</td>
                    <td class="t-xs dim">{{ formatDate(tag.updated_at) }}</td>
                    <td>
                      <div class="row gap-1 end">
                        <button class="btn btn--success btn--sm" :disabled="busy" @click="save(tag)">Save</button>
                        <button class="btn btn--sm" @click="editingId = null">Cancel</button>
                      </div>
                    </td>
                  </template>
                  <template v-else>
                    <td><span class="chip"><app-icon name="tag" :size="11"/>{{ tag.name }}</span></td>
                    <td class="mono t-xs dim">{{ tag.value || '—' }}</td>
                    <td class="table__num nums" :class="tag.usage_count ? '' : 'faint'">{{ tag.usage_count }}</td>
                    <td class="t-xs dim">{{ formatDate(tag.updated_at) }}</td>
                    <td>
                      <div class="row gap-1 end">
                        <button class="btn btn--ghost btn--sm btn--icon" title="Edit" @click="startEdit(tag)">
                          <app-icon name="pencil" :size="13"/>
                        </button>
                        <button class="btn btn--ghost btn--sm btn--icon" title="Delete" :disabled="busy"
                                @click="confirmDelete = tag">
                          <app-icon name="trash" :size="13"/>
                        </button>
                      </div>
                    </td>
                  </template>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <empty-state v-else-if="state.tags.length" icon="search" title="No tag matches this search"/>
        <empty-state v-else icon="tag" title="No tags yet"
                     hint="Tags travel with a course into BookStack. Create them here, or let the AI propose them from the course structure."/>

        <div v-if="pageCount > 1" class="row between mt-3 t-xs dim">
          <span>{{ plural(sorted.length, 'tag') }} · page {{ page }} of {{ pageCount }}</span>
          <div class="row gap-1">
            <button class="btn btn--sm btn--icon" :disabled="page === 1" @click="page = 1">
              <app-icon name="chevrons-left" :size="13"/>
            </button>
            <button class="btn btn--sm btn--icon" :disabled="page === 1" @click="page--">
              <app-icon name="chevron-left" :size="13"/>
            </button>
            <button class="btn btn--sm btn--icon" :disabled="page === pageCount" @click="page++">
              <app-icon name="chevron-right" :size="13"/>
            </button>
            <button class="btn btn--sm btn--icon" :disabled="page === pageCount" @click="page = pageCount">
              <app-icon name="chevrons-right" :size="13"/>
            </button>
          </div>
        </div>
      </div>
    </div>

    <app-modal v-if="confirmDelete" title="Delete this tag?" icon="alert" @close="confirmDelete = null">
      <p class="t-sm">
        <strong>{{ confirmDelete.name }}</strong>
        <span v-if="confirmDelete.usage_count"> is attached {{ confirmDelete.usage_count }} time(s) and will be detached everywhere.</span>
        <span v-else> is not attached to anything.</span>
      </p>
      <template #footer>
        <button class="btn" @click="confirmDelete = null">Cancel</button>
        <button class="btn btn--danger" :disabled="busy" @click="remove(confirmDelete)">
          <app-icon name="trash" :size="14"/> Delete
        </button>
      </template>
    </app-modal>`,
};

export default TagsView;
