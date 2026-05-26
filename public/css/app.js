const app = Vue.createApp({
    data() {
        return {
            tasks: [],
            newTask: {
                name: '',
                phone: '',
                keywords: ''
            }
        }
    },
    mounted() {
        this.fetchTasks();
    },
    methods: {
        async fetchTasks() {
            const response = await fetch('/api/task');
            this.tasks = await response.json();
        },
        async addTask() {
            const response = await fetch('/api/task', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(this.newTask)
            });
            
            if (response.ok) {
                this.newTask = { name: '', phone: '', keywords: '' };
                this.fetchTasks();
            }
        },
        async startTask(id) {
            const response = await fetch(`/api/task/${id}/start`, {
                method: 'POST'
            });
            
            if (response.ok) {
                this.fetchTasks();
            }
        },
        async stopTask(id) {
            const response = await fetch(`/api/task/${id}/stop`, {
                method: 'POST'
            });
            
            if (response.ok) {
                this.fetchTasks();
            }
        },
        async deleteTask(id) {
            const response = await fetch(`/api/task/${id}`, {
                method: 'DELETE'
            });
            
            if (response.ok) {
                this.fetchTasks();
            }
        }
    }
});

app.mount('#app');
