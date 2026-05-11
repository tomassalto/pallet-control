import { Component } from "react";

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    console.error("[ErrorBoundary]", error, info.componentStack);
  }

  render() {
    if (this.state.error) {
      return (
        <div className="min-h-screen flex items-center justify-center p-6 bg-gray-50 dark:bg-gray-950">
          <div className="max-w-md w-full space-y-4 text-center">
            <div className="text-4xl">⚠️</div>
            <h1 className="text-xl font-bold text-gray-900 dark:text-white">
              Algo salió mal
            </h1>
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {this.state.error?.message || "Error inesperado"}
            </p>
            <button
              onClick={() => this.setState({ error: null })}
              className="inline-block px-5 py-2.5 rounded-xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-sm font-semibold hover:opacity-90 transition-opacity"
            >
              Reintentar
            </button>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}
