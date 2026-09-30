import { createStore } from 'vuex';
import {deepParse} from "@/utils.js";

const store = createStore({
    state: {
        user: {},
    },
    mutations: {
        setUser(state, newValue) {
            state.user = deepParse(newValue);
        },
    },
    actions: {
        updateUser ({ commit }, newValue) {
            commit('setUser', newValue);
        },
    },
});

export default store;
